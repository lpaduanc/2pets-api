<?php

namespace App\Services\Hospitalization;

use App\Enums\MedicalRecordStatus;
use App\Enums\ServiceCategory;
use App\Models\Appointment;
use App\Models\Hospitalization;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\Invoice\AppointmentChargeService;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §2.2,
 * pendência marcada como "sinalizada, não decidida" na entrega anterior e resolvida
 * nesta: um ato clínico Grupo A (ex.: cirurgia) ocorrido DURANTE uma internação já em
 * curso não passa por `ConsultationService::start()` (que criaria um segundo
 * `Appointment`/`Invoice` — exatamente o que o doc 11 veta) nem por `assertStartable()`
 * (a internação já está `IN_PROGRESS`, não `SCHEDULED`/`CONFIRMED`). Em vez disso, este
 * service cria o `MedicalRecord` diretamente, pendurado no `appointment_id` da PRÓPRIA
 * internação, e lança a cobrança correspondente na mesma comanda — mesma transação, mesma
 * autorização (`AppointmentPolicy::manageCharges`), mesmo dono de fatura que
 * `HospitalizationExamService` já usa para exame durante internação (o precedente exato
 * que este service espelha).
 *
 * O prontuário nasce `draft`, igual a qualquer outro — edição de campos clínicos
 * (`PUT professional/medical-records/{id}`), anexos, prescrições e finalização
 * (`POST professional/medical-records/{id}/finalize`) reaproveitam os endpoints que já
 * existem para qualquer `MedicalRecord`, sem nenhuma rota nova: nada aqui antecipa o
 * módulo clínico de internação (ficha/evolução diária), só abre o registro certo.
 */
final class HospitalizationClinicalActService
{
    public function __construct(
        private readonly AppointmentChargeService $chargeService,
        private readonly Gate $gate,
    ) {}

    /**
     * @param  array{category: string, reason: ?string, record_date: ?string, service_id: ?int, unit_price: ?float}  $data
     */
    public function openAct(Hospitalization $hospitalization, User $professional, array $data): MedicalRecord
    {
        $this->assertActive($hospitalization);

        $category = $this->resolveCategory($data['category'], $professional);
        $appointment = $hospitalization->loadMissing('appointment')->appointment;

        $this->assertCanManageCharges($professional, $appointment);

        return DB::transaction(function () use ($appointment, $hospitalization, $professional, $category, $data): MedicalRecord {
            $record = $this->createMedicalRecord($appointment, $hospitalization, $professional, $category, $data);
            $this->createCharge($appointment, $professional, $category, $data);

            return $record;
        });
    }

    private function assertActive(Hospitalization $hospitalization): void
    {
        if (! $hospitalization->isActive()) {
            abort(422, 'Só é possível abrir um ato clínico numa internação ativa.');
        }
    }

    /**
     * A checagem ESTRUTURAL (a categoria pertence ao Grupo A) já aconteceu no Form
     * Request (`Rule::in(ServiceCategory::groupA())`). O que falta aqui é a checagem
     * DINÂMICA — depende de QUEM está autenticado, contrato
     * docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §1: nutrição
     * e comportamento só são ato clínico exclusivo quando o executor é veterinário.
     */
    private function resolveCategory(string $value, User $professional): ServiceCategory
    {
        $category = ServiceCategory::from($value);

        if ($category->isVeterinarianGated() && ! $professional->isVeterinarian()) {
            abort(422, 'Esta categoria de ato clínico exige um profissional veterinário.');
        }

        return $category;
    }

    /**
     * Mesma autorização de quem edita a comanda da internação
     * (`HospitalizationExamService::createDuringActiveStay`) — abrir um ato clínico aqui
     * SEMPRE lança uma cobrança na mesma comanda, então exige a mesma permissão de quem
     * mexe em dinheiro do atendimento, não só acesso ao pet.
     */
    private function assertCanManageCharges(User $professional, Appointment $appointment): void
    {
        if ($this->gate->forUser($professional)->denies('manageCharges', $appointment)) {
            abort(403, 'Você não pode abrir um ato clínico nesta internação.');
        }
    }

    /**
     * @param  array{reason: ?string, record_date: ?string}  $data
     */
    private function createMedicalRecord(
        Appointment $appointment,
        Hospitalization $hospitalization,
        User $professional,
        ServiceCategory $category,
        array $data,
    ): MedicalRecord {
        return MedicalRecord::create([
            'pet_id' => $hospitalization->pet_id,
            'professional_id' => $professional->id,
            'appointment_id' => $appointment->id,
            'act_category' => $category->value,
            'record_date' => $data['record_date'] ?? now()->toDateString(),
            'status' => MedicalRecordStatus::DRAFT->value,
            // `chief_complaint` NÃO serve aqui: é um slug de um CHECK fechado
            // (`ValidChiefComplaint`/`medical_records_chief_complaint_check`, ex.:
            // `vomiting`, `routine_checkup`) para o motivo de QUEIXA do tutor — texto
            // livre ali quebraria a constraint. `reason` é texto livre descrevendo o
            // ato (ex.: "Ovariohisterectomia"), então vai em `notes`.
            'notes' => $data['reason'] ?? null,
        ]);
    }

    /**
     * @param  array{reason: ?string, service_id: ?int, unit_price: ?float}  $data
     */
    private function createCharge(Appointment $appointment, User $professional, ServiceCategory $category, array $data): void
    {
        $description = ($data['reason'] ?? $category->label()).' — durante internação';

        $this->chargeService->create($appointment, $professional, [
            'service_id' => $data['service_id'] ?? null,
            'description' => $description,
            'unit_price' => $data['unit_price'] ?? null,
        ]);
    }
}
