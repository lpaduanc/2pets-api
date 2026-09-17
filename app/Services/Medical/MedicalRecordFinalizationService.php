<?php

namespace App\Services\Medical;

use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Enums\MedicalRecordStatus;
use App\Enums\ServiceCategory;
use App\Events\MedicalRecordFinalized;
use App\Exceptions\Medical\MedicalRecordFinalizationException;
use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\MedicalRecordAddendum;
use App\Models\User;
use App\Services\Pet\PetWeightHistoryWriter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finalização = trava os campos clínicos (contrato §1.5) + gatilho do convite de avaliação
 * (item 12 do MVP). Correção depois é sempre `MedicalRecordAddendum`, nunca reabertura.
 *
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.5: a fatura
 * do atendimento NÃO nasce mais aqui — ela já existe (`pending`) desde
 * `ConsultationService::start()`. Finalizar o prontuário não tem mais nenhum efeito sobre
 * faturamento; itens continuam entrando via `AppointmentChargeService` até a fatura ser paga.
 */
final class MedicalRecordFinalizationService
{
    private const DEFAULT_FOLLOW_UP_DURATION_MINUTES = 30;

    public function __construct(
        private readonly PrescriptionLifecycleService $prescriptionLifecycleService,
        private readonly PetWeightHistoryWriter $weightHistoryWriter,
    ) {}

    /**
     * @param  array{date: string, reason?: ?string}|null  $followUp
     */
    public function finalize(MedicalRecord $medicalRecord, User $professional, ?array $followUp = null): MedicalRecord
    {
        return DB::transaction(function () use ($medicalRecord, $professional, $followUp): MedicalRecord {
            $this->assertCanFinalize($medicalRecord);

            $finalizedAt = now();

            $medicalRecord->update([
                'status' => MedicalRecordStatus::FINALIZED->value,
                'finalized_at' => $finalizedAt,
                'finalized_by' => $professional->id,
            ]);

            $this->recordWeightMeasurement($medicalRecord, $professional);

            // Contrato docs/atendimento-veterinario/03-contrato-receituario.md §1: toda
            // `Prescription` vinculada a este prontuário e ainda não emitida vira imutável no
            // MESMO instante da finalização — na mesma transação, para nunca existir um estado
            // em que o prontuário está finalizado mas a receita continua editável.
            $this->prescriptionLifecycleService->issueForMedicalRecord($medicalRecord, $finalizedAt);

            if ($followUp !== null) {
                $this->attachFollowUp($medicalRecord, $followUp);
            }

            MedicalRecordFinalized::dispatch($medicalRecord);

            return $medicalRecord;
        });
    }

    /**
     * Correção pós-finalização — sempre uma entrada NOVA, nunca reescrita do conteúdo
     * original (Res. CFMV nº 1.321/2020 alt. 1.653/2025). Só faz sentido sobre um prontuário
     * já `finalized` — a `MedicalRecordPolicy::addAddendum` já garante isso antes de chegar
     * aqui, mas o service não confia cegamente no controller.
     */
    public function addAddendum(MedicalRecord $medicalRecord, User $author, string $body): MedicalRecordAddendum
    {
        if (! $medicalRecord->isFinalized()) {
            throw MedicalRecordFinalizationException::notFinalizedYet();
        }

        return $medicalRecord->addenda()->create([
            'author_id' => $author->id,
            'body' => $body,
        ]);
    }

    /**
     * Única regra que trava (contrato §3): diagnóstico OU plano de tratamento preenchido, e
     * peso presente. Checada contra o ESTADO PERSISTIDO do prontuário — esses campos já
     * foram salvos via `PUT` antes da finalização, o corpo de `finalize()` só carrega o
     * retorno opcional.
     *
     * EXCEÇÃO — vacinação: uma vacinação pura não tem diagnóstico nem plano de tratamento, e
     * exigi-los obrigaria o veterinário a inventar texto só para conseguir fechar o
     * atendimento (o pior resultado possível: campo clínico preenchido com lixo, que degrada
     * todo o histórico do pet). Aqui só o peso é exigido — ele alimenta `PetWeightHistory` e o
     * cálculo de porte, e é medido de qualquer forma antes de vacinar.
     */
    private function assertCanFinalize(MedicalRecord $medicalRecord): void
    {
        if ($medicalRecord->isFinalized()) {
            throw MedicalRecordFinalizationException::alreadyFinalized();
        }

        if ($medicalRecord->weight === null) {
            throw $this->isVaccinationEncounter($medicalRecord)
                ? MedicalRecordFinalizationException::missingWeight()
                : MedicalRecordFinalizationException::missingRequiredFields();
        }

        if ($this->isVaccinationEncounter($medicalRecord)) {
            return;
        }

        if (! filled($medicalRecord->diagnosis) && ! filled($medicalRecord->treatment_plan)) {
            throw MedicalRecordFinalizationException::missingRequiredFields();
        }
    }

    /**
     * O tipo vem do agendamento, mesma fonte que o frontend usa para escolher a variação do
     * formulário (`resolveEncounterVariant`, contrato §5.5). Prontuário sem agendamento
     * (nunca criado pelo fluxo de atendimento) cai na regra geral, que é a mais exigente.
     */
    private function isVaccinationEncounter(MedicalRecord $medicalRecord): bool
    {
        return $medicalRecord->loadMissing('appointment')->appointment?->type === ServiceCategory::VACCINATION->value;
    }

    /**
     * O peso é o único campo clínico obrigatório para finalizar, e a justificativa (doc de
     * domínio §6.5) é justamente que ele alimenta `PetWeightHistory` e o cálculo de porte.
     * Sem esta gravação a justificativa era falsa: `PetTimelineService` LÊ o histórico de
     * peso, mas nada escrevia nele no fim do atendimento — toda pesagem feita em consulta se
     * perdia, e a linha do tempo do pet nunca mostrava a curva real.
     *
     * Idempotente por finalização: só grava uma vez, porque `finalize()` recusa prontuário
     * que já está `finalized`.
     */
    private function recordWeightMeasurement(MedicalRecord $medicalRecord, User $professional): void
    {
        $this->weightHistoryWriter->recordIfPresent(
            $medicalRecord->pet_id,
            $professional,
            $medicalRecord->weight,
            $medicalRecord->finalized_at ?? now(),
        );
    }

    /**
     * Retorno agendado é SEMPRE uma consulta (decisão do dono do produto), nunca herda o
     * `type` do agendamento do ato que está sendo finalizado. Retorno pós-cirurgia é revisão
     * pós-operatória, retorno pós-internação é reavaliação, retorno pós-emergência é
     * reavaliação — nenhum dos três é "outra cirurgia"/"outra internação"/"outra emergência".
     * Antes desta correção o retorno herdava o `type` do atendimento de origem, o que ficou
     * gritante quando o ato estava pendurado numa internação: o retorno nascia
     * `type=hospitalization` e aparecia na agenda como se fosse mais uma internação.
     */
    private function attachFollowUp(MedicalRecord $medicalRecord, array $followUp): void
    {
        $medicalRecord->loadMissing('pet');

        $date = Carbon::parse($followUp['date']);

        $appointment = Appointment::create([
            'professional_id' => $medicalRecord->professional_id,
            'client_id' => $medicalRecord->pet->user_id,
            'pet_id' => $medicalRecord->pet_id,
            'appointment_date' => $date->copy()->startOfDay(),
            'appointment_time' => $date->format('H:i'),
            'duration' => self::DEFAULT_FOLLOW_UP_DURATION_MINUTES,
            'type' => ServiceCategory::CONSULTATION->value,
            'status' => AppointmentStatus::SCHEDULED->value,
            'reason' => $followUp['reason'] ?? 'Retorno',
            'booking_source' => BookingSource::PROFESSIONAL->value,
        ]);

        $medicalRecord->update(['follow_up_appointment_id' => $appointment->id]);
    }
}
