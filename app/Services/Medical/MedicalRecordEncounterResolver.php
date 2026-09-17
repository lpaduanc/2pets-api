<?php

namespace App\Services\Medical;

use App\DataTransferObjects\EncounterResolution;
use App\Enums\EncounterKind;
use App\Enums\MedicalRecordStatus;
use App\Enums\ServiceCategory;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Exam;
use App\Models\MedicalRecord;
use App\Models\User;

/**
 * Decide se um atendimento gera `MedicalRecord`, `Exam` ou nenhum artefato clínico, e
 * resolve (ou reaproveita) o registro certo. Contrato
 * docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §2/§3 — três ramos:
 *
 * - **Clínico** (`ServiceCategory::isExam()`/`isNonClinical()` ambos falsos): `MedicalRecord`,
 *   como sempre foi.
 * - **Exame** (`laboratory`/`imaging`): `Exam` vinculado ao agendamento — o laudo
 *   (`ExamResult`) é outro relógio, não trava o fechamento.
 * - **Não clínico** (`grooming`, `training`, `boarding`, `other`, `hospitalization`):
 *   nenhum registro nasce.
 *
 * `nutrition`/`behavioral` alternam entre clínico e não clínico conforme
 * `$professional->isVeterinarian()` — nutrição/comportamento só são ato exclusivo de
 * veterinário quando é um veterinário quem executa.
 */
final class MedicalRecordEncounterResolver
{
    public function __construct(
        private readonly ExamService $examService,
    ) {}

    /**
     * Idempotente: reaproveita o rascunho/exame já existente na segunda chamada (`start()`
     * chamado de novo sobre o mesmo agendamento), nunca cria um segundo.
     */
    public function resolve(Appointment $appointment, User $professional): EncounterResolution
    {
        return match ($this->resolveKind($appointment, $professional)) {
            EncounterKind::EXAM => EncounterResolution::forExam($this->resolveExam($appointment, $professional)),
            EncounterKind::CLINICAL => EncounterResolution::forMedicalRecord($this->resolveMedicalRecord($appointment, $professional)),
            EncounterKind::NON_CLINICAL => EncounterResolution::none(),
        };
    }

    /**
     * Tipo sem correspondência em `ServiceCategory` (linha histórica anterior ao backfill,
     * ou dado de teste solto) cai no ramo mais exigente — clínico — em vez de silenciosamente
     * deixar de gerar prontuário.
     */
    private function resolveKind(Appointment $appointment, User $professional): EncounterKind
    {
        $category = ServiceCategory::tryFrom($appointment->type);

        if ($category === null) {
            return EncounterKind::CLINICAL;
        }

        if ($category->isExam()) {
            return EncounterKind::EXAM;
        }

        if ($category->isVeterinarianGated()) {
            return $professional->isVeterinarian() ? EncounterKind::CLINICAL : EncounterKind::NON_CLINICAL;
        }

        return $category->isNonClinical() ? EncounterKind::NON_CLINICAL : EncounterKind::CLINICAL;
    }

    private function resolveMedicalRecord(Appointment $appointment, User $professional): MedicalRecord
    {
        return MedicalRecord::where('appointment_id', $appointment->id)->first()
            ?? MedicalRecord::create([
                'pet_id' => $appointment->pet_id,
                'professional_id' => $professional->id,
                'appointment_id' => $appointment->id,
                'record_date' => now()->toDateString(),
                'status' => MedicalRecordStatus::DRAFT->value,
            ]);
    }

    /**
     * `exam_type` é a própria categoria (`laboratory`/`imaging`) — já é o dado mais preciso
     * disponível, sem inventar uma segunda taxonomia. `exam_date` é a data do agendamento
     * (contrato §2), não `now()`: um atendimento iniciado logo depois da meia-noite não pode
     * registrar o exame num dia diferente do agendado.
     */
    private function resolveExam(Appointment $appointment, User $professional): Exam
    {
        $existing = Exam::where('appointment_id', $appointment->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $category = ServiceCategory::tryFrom($appointment->type) ?? ServiceCategory::LABORATORY;

        return $this->examService->createExam(
            pet: $appointment->loadMissing('pet')->pet,
            professional: $professional,
            examType: $category->value,
            examName: $this->resolveExamName($appointment, $category),
            examDate: $appointment->appointment_date->copy(),
            appointmentId: $appointment->id,
        );
    }

    /**
     * O nome do exame vem do serviço da mesma categoria anexado ao agendamento
     * (`appointment_services`, contrato docs/atendimento-veterinario/
     * 09-faturamento-do-atendimento.md §13.2) — é o dado mais específico que existe
     * ("Eletrocardiograma (ECG)" em vez de só "Imaging"). Sem serviço anexado (agendamento
     * antigo, criado antes da pivô existir), cai no rótulo genérico da categoria.
     */
    private function resolveExamName(Appointment $appointment, ServiceCategory $category): string
    {
        $matchingLine = $appointment->loadMissing('services.service')->services
            ->first(fn (AppointmentService $candidate): bool => $candidate->service?->category === $category->value);

        return $matchingLine?->service?->name ?? $category->label();
    }
}
