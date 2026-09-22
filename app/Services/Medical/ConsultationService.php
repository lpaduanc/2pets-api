<?php

namespace App\Services\Medical;

use App\DataTransferObjects\ConsultationStarted;
use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Exceptions\Appointment\UnstartableAppointmentException;
use App\Models\Appointment;
use App\Models\Pet;
use App\Models\User;
use App\Services\Appointment\AppointmentStatusTransitionService;
use App\Services\Invoice\AppointmentInvoiceService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Orquestra o início/encerramento de um atendimento — contrato
 * docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md §1,
 * docs/atendimento-veterinario/00-dominio-e-escopo.md §1.2/§1.3/§1.4,
 * docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §A (a consulta em
 * si já não depende mais de `PetVetAccess` — o agendamento confirmado é o portão) e
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.4/§13.5 (nem todo
 * atendimento gera prontuário, mas todo atendimento iniciado lança fatura).
 */
final class ConsultationService
{
    private const DEFAULT_WALK_IN_DURATION_MINUTES = 30;

    /**
     * Estados a partir dos quais `start()` é permitido. `IN_PROGRESS` entra porque `start` é
     * idempotente por contrato — reabrir o rascunho não pode quebrar.
     *
     * @var list<AppointmentStatus>
     */
    private const STARTABLE_STATUSES = [
        AppointmentStatus::SCHEDULED,
        AppointmentStatus::CONFIRMED,
        AppointmentStatus::IN_PROGRESS,
    ];

    public function __construct(
        private readonly AppointmentStatusTransitionService $statusTransitionService,
        private readonly WalkInAuthorizationGuard $walkInAuthorizationGuard,
        private readonly AppointmentInvoiceService $invoiceService,
        private readonly MedicalRecordEncounterResolver $medicalRecordResolver,
    ) {}

    /**
     * `SCHEDULED|CONFIRMED → IN_PROGRESS` + cria (ou reabre) o artefato clínico certo —
     * `MedicalRecord` para tipo clínico, `Exam` para `laboratory`/`imaging`, nenhum para o
     * resto (contrato docs/atendimento-veterinario/
     * 10-taxonomia-servico-tipo-atendimento.md §2/§3) — + lança a fatura `pending` a partir
     * dos serviços do agendamento (contrato §13.1/§13.5). Idempotente: uma segunda chamada
     * no mesmo `Appointment` devolve o mesmo trio (rascunho/exame, fatura), nunca cria um
     * segundo.
     *
     * @throws UnstartableAppointmentException quando o status atual não permite iniciar.
     */
    public function start(Appointment $appointment, User $professional): ConsultationStarted
    {
        $this->assertStartable($appointment);

        return DB::transaction(function () use ($appointment, $professional): ConsultationStarted {
            $this->transitionToInProgress($appointment);

            $encounter = $this->medicalRecordResolver->resolve($appointment, $professional);
            $this->invoiceService->ensurePendingInvoice($appointment);

            return new ConsultationStarted($appointment, $encounter->medicalRecord, $encounter->exam);
        });
    }

    /**
     * Encaixe/emergência: cria o `Appointment` já `CONFIRMED` — pulando pendente/agendado,
     * que não fazem sentido quando o animal já está na sala — e entra direto em atendimento.
     *
     * `client_id` é SEMPRE derivado de `pet.user_id`, nunca aceito do payload (contrato §3).
     *
     * Como o walk-in cria o próprio agendamento, ele não pode se autoautorizar por status —
     * o portão aqui é outro (contrato §A): o tutor do pet já precisa ser cliente deste
     * profissional (`ProfessionalClientsQuery`, as mesmas 4 fontes do `professional/clients`)
     * OU o profissional já ter `PetVetAccess` ativo sobre o pet.
     *
     * @param  array{pet_id: int, type: string, reason?: ?string, duration?: ?int}  $data
     *
     * @throws HttpException 403 quando nenhuma das duas condições acima se verifica.
     */
    public function startWalkIn(array $data, User $professional): ConsultationStarted
    {
        return DB::transaction(function () use ($data, $professional): ConsultationStarted {
            $pet = Pet::findOrFail($data['pet_id']);

            $this->walkInAuthorizationGuard->assertAuthorized($pet, $professional);

            $appointment = Appointment::create([
                'professional_id' => $professional->id,
                'client_id' => $pet->user_id,
                'pet_id' => $pet->id,
                'appointment_date' => now(),
                'appointment_time' => now()->format('H:i'),
                'duration' => $data['duration'] ?? self::DEFAULT_WALK_IN_DURATION_MINUTES,
                'type' => $data['type'],
                'appointment_type_id' => $data['appointment_type_id'] ?? null,
                'status' => AppointmentStatus::CONFIRMED->value,
                'reason' => $data['reason'] ?? null,
                'booking_source' => BookingSource::PROFESSIONAL->value,
                'requires_confirmation' => false,
                'confirmed_at' => now(),
            ]);

            return $this->start($appointment, $professional);
        });
    }

    /**
     * Fecha o compromisso de agenda (`IN_PROGRESS → COMPLETED`) sem forçar a finalização do
     * prontuário — que continua `draft` e alimenta o painel de pendências
     * (`GET professional/medical-records/pending`). Concluir a agenda e finalizar o
     * prontuário são ações diferentes de propósito (doc de domínio §1.4).
     */
    public function closeWithoutFinalizing(Appointment $appointment): Appointment
    {
        $data = $this->statusTransitionService->prepareTransition(
            $appointment,
            ['status' => AppointmentStatus::COMPLETED->value]
        );

        $appointment->update($data);

        return $appointment;
    }

    /**
     * `SCHEDULED` não pula direto para `IN_PROGRESS` na máquina de estados
     * (`AppointmentStatus::allowedTransitions()`) — só `CONFIRMED` faz essa transição. O
     * contrato §A aceita `scheduled` como startável (o profissional criou o compromisso e o
     * paciente chegou, sem uma etapa manual de "confirmar" separada), então este método faz
     * os dois saltos válidos na mesma transação em vez de abrir uma exceção à máquina de
     * estados. Reenviar o mesmo status nunca é inválido — ver `AppointmentStatus::canTransitionTo()`.
     */
    private function transitionToInProgress(Appointment $appointment): void
    {
        $current = AppointmentStatus::from($appointment->status);

        if ($current === AppointmentStatus::IN_PROGRESS) {
            return;
        }

        if ($current === AppointmentStatus::SCHEDULED) {
            $appointment->update($this->statusTransitionService->prepareTransition(
                $appointment,
                ['status' => AppointmentStatus::CONFIRMED->value]
            ));
        }

        $appointment->update($this->statusTransitionService->prepareTransition(
            $appointment,
            ['status' => AppointmentStatus::IN_PROGRESS->value]
        ));
    }

    private function assertStartable(Appointment $appointment): void
    {
        $status = AppointmentStatus::from($appointment->status);

        if (! in_array($status, self::STARTABLE_STATUSES, true)) {
            throw UnstartableAppointmentException::forStatus($status);
        }
    }
}
