<?php

namespace App\Services\Hospitalization;

use App\Enums\HospitalizationStatus;
use App\Models\Exam;
use App\Models\Hospitalization;
use App\Models\Pet;
use App\Models\User;
use App\Services\Invoice\AppointmentChargeService;
use App\Services\Medical\ExamService;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §2.2.
 *
 * Exceção explícita à regra geral do doc 10 §3: um exame (`laboratory`/`imaging`)
 * solicitado com o pet em internação ativa NÃO passa por `ConsultationService::start()`
 * (que criaria um segundo `Appointment`/`Invoice`) — o `Exam` nasce vinculado ao
 * `appointment_id` da PRÓPRIA internação, e uma linha correspondente entra na mesma
 * conta, na mesma transação. Fora de uma internação ativa, o comportamento de
 * `POST /exams` não muda em nada.
 */
final class HospitalizationExamService
{
    public function __construct(
        private readonly ExamService $examService,
        private readonly AppointmentChargeService $chargeService,
        private readonly Gate $gate,
    ) {}

    /**
     * @param  array{exam_type: string, exam_name: string, exam_date: \Carbon\Carbon, notes: ?string, appointment_id: ?int, service_id: ?int, unit_price: ?float, exam_type_id: ?int}  $data
     */
    public function createExam(Pet $pet, User $professional, array $data): Exam
    {
        $hospitalization = $this->activeHospitalizationFor($pet);

        if ($hospitalization === null) {
            return $this->examService->createExam(
                pet: $pet,
                professional: $professional,
                examType: $data['exam_type'],
                examName: $data['exam_name'],
                examDate: $data['exam_date'],
                notes: $data['notes'],
                appointmentId: $data['appointment_id'],
                examTypeId: $data['exam_type_id'] ?? null,
            );
        }

        return $this->createDuringActiveStay($hospitalization, $professional, $data);
    }

    /**
     * @param  array{exam_type: string, exam_name: string, exam_date: \Carbon\Carbon, notes: ?string, service_id: ?int, unit_price: ?float, exam_type_id: ?int}  $data
     */
    private function createDuringActiveStay(Hospitalization $hospitalization, User $professional, array $data): Exam
    {
        $appointment = $hospitalization->loadMissing('appointment')->appointment;

        // Mesma autorização de quem edita a conta (`AppointmentPolicy::manageCharges`) —
        // `resolvePetForWrite` (chamado antes, no controller) só garante acesso ao PET, não
        // autoriza lançar cobrança na internação de outro profissional.
        if ($this->gate->forUser($professional)->denies('manageCharges', $appointment)) {
            abort(403, 'Você não pode lançar cobrança nesta internação.');
        }

        return DB::transaction(function () use ($appointment, $hospitalization, $professional, $data): Exam {
            $exam = $this->examService->createExam(
                pet: $hospitalization->pet,
                professional: $professional,
                examType: $data['exam_type'],
                examName: $data['exam_name'],
                examDate: $data['exam_date'],
                notes: $data['notes'],
                appointmentId: $appointment->id,
                examTypeId: $data['exam_type_id'] ?? null,
            );

            $this->chargeService->create($appointment, $professional, [
                'service_id' => $data['service_id'],
                'description' => $data['exam_name'].' — durante internação',
                'unit_price' => $data['unit_price'],
            ]);

            return $exam;
        });
    }

    private function activeHospitalizationFor(Pet $pet): ?Hospitalization
    {
        return Hospitalization::query()
            ->where('pet_id', $pet->id)
            ->where('status', HospitalizationStatus::ACTIVE->value)
            ->first();
    }
}
