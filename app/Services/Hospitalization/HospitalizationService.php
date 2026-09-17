<?php

namespace App\Services\Hospitalization;

use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Enums\HospitalizationStatus;
use App\Enums\ServiceCategory;
use App\Models\Appointment;
use App\Models\Hospitalization;
use App\Models\Pet;
use App\Models\User;
use App\Services\Appointment\AppointmentServicesWriter;
use App\Services\Medical\ConsultationService;
use App\Services\Medical\WalkInAuthorizationGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §1/§2/§3.
 *
 * Internação pendura num `Appointment` (`type = hospitalization`) que fica `IN_PROGRESS`
 * pelos dias inteiros da estadia — reaproveita `ConsultationService`/`AppointmentServicesWriter`
 * sem alterar a lógica deles, nunca duplica a máquina de estados nem a criação de fatura.
 */
final class HospitalizationService
{
    /**
     * Duração nominal do "compromisso" de admissão — a estadia real não tem duração fixa
     * (fica `IN_PROGRESS` até a alta, contrato §0), este valor só preenche a coluna
     * `appointments.duration` (`NOT NULL`), mesmo padrão de
     * `ConsultationService::DEFAULT_WALK_IN_DURATION_MINUTES`.
     */
    private const ADMISSION_DURATION_MINUTES = 30;

    public function __construct(
        private readonly WalkInAuthorizationGuard $authorizationGuard,
        private readonly ConsultationService $consultationService,
        private readonly AppointmentServicesWriter $servicesWriter,
    ) {}

    /**
     * (1) autoriza como um walk-in (mesmo portão, contrato §6); (2) cria o `Appointment` já
     * `CONFIRMED`, animal fisicamente presente; (3) anexa `services[]` opcional ANTES de
     * iniciar, para `ConsultationService::start()` já lançar a fatura `pending` com eles
     * (invariante 11 do doc 09); (4) inicia o atendimento (`IN_PROGRESS`); (5) grava a ficha
     * de internação vinculada.
     *
     * @param  array{pet_id: int, admission_date: string, reason: string, services?: array<int, array{service_id: int, quantity?: ?float, unit_price?: ?float}>, indicating_medical_record_id?: ?int, estimated_discharge_date?: ?string, medications?: ?array<mixed>}  $data
     */
    public function admit(array $data, User $professional): Hospitalization
    {
        return DB::transaction(function () use ($data, $professional): Hospitalization {
            $pet = Pet::findOrFail($data['pet_id']);
            $this->authorizationGuard->assertAuthorized($pet, $professional);

            $appointment = $this->createAdmissionAppointment($pet, $professional, $data);
            $this->attachServicesIfAny($appointment, $data['services'] ?? null);

            $this->consultationService->start($appointment, $professional);

            return Hospitalization::create([
                'pet_id' => $pet->id,
                'professional_id' => $professional->id,
                'appointment_id' => $appointment->id,
                'indicating_medical_record_id' => $data['indicating_medical_record_id'] ?? null,
                'admission_date' => $data['admission_date'],
                'estimated_discharge_date' => $data['estimated_discharge_date'] ?? null,
                'reason' => $data['reason'],
                'status' => HospitalizationStatus::ACTIVE->value,
                'medications' => $data['medications'] ?? null,
            ]);
        });
    }

    /**
     * Internações visíveis a este usuário — contrato
     * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §6.2: o próprio autor,
     * mais (quando o usuário tem `medical-records.create`) qualquer internação admitida por
     * um colega ATIVO na mesma organização dele. Sem organização/permissão clínica, só as
     * próprias — reflexo real do vet volante autônomo, sem "colega de plantão" nenhum.
     *
     * Fragilidade sabida: usa a organização "ativa" do REQUISITANTE (`activeOrganizationId()`,
     * que prioriza vínculo `owner`) como o único ponto de comparação — alguém com vínculo
     * ativo em MAIS de uma organização só enxerga internações da organização primária, não de
     * todas. Mesmo trade-off que `AppointmentPolicy`/`InvoicePolicy` já aceitam hoje.
     */
    public function visibleTo(User $user): Builder
    {
        return Hospitalization::query()->where(function (Builder $query) use ($user): void {
            $query->where('professional_id', $user->id);

            $organizationId = $user->activeOrganizationId();

            if ($organizationId !== null && $user->hasPermissionTo('medical-records.create')) {
                $query->orWhereHas(
                    'professional.activeOrganizationMemberships',
                    fn (Builder $membership) => $membership->where('organization_id', $organizationId),
                );
            }
        });
    }

    /**
     * Alta, transferência ou óbito fecham a estadia igualmente (contrato §3) — as três
     * fecham o `Appointment` pelo mesmo `closeWithoutFinalizing()`, sem `mark-as-paid`
     * nenhum disparado daqui (pagamento continua sempre uma ação manual separada).
     *
     * @param  array{discharge_date?: ?string, estimated_discharge_date?: ?string, reason?: string, status?: string, discharge_summary?: ?string, medications?: ?array<mixed>}  $data
     */
    public function update(Hospitalization $hospitalization, array $data): Hospitalization
    {
        if ($this->closesStay($data)) {
            $hospitalization->loadMissing('appointment');
            $this->consultationService->closeWithoutFinalizing($hospitalization->appointment);
        }

        $hospitalization->update($data);

        return $hospitalization;
    }

    private function closesStay(array $data): bool
    {
        return array_key_exists('status', $data) && HospitalizationStatus::from($data['status'])->isClosed();
    }

    /**
     * @param  array{pet_id: int, admission_date: string, reason: string}  $data
     */
    private function createAdmissionAppointment(Pet $pet, User $professional, array $data): Appointment
    {
        return Appointment::create([
            'professional_id' => $professional->id,
            'client_id' => $pet->user_id,
            'pet_id' => $pet->id,
            'appointment_date' => $data['admission_date'],
            'appointment_time' => now()->format('H:i'),
            'duration' => self::ADMISSION_DURATION_MINUTES,
            'type' => ServiceCategory::HOSPITALIZATION->value,
            'status' => AppointmentStatus::CONFIRMED->value,
            'reason' => $data['reason'],
            'booking_source' => BookingSource::PROFESSIONAL->value,
            'requires_confirmation' => false,
            'confirmed_at' => now(),
        ]);
    }

    /**
     * @param  ?array<int, array{service_id: int, quantity?: ?float, unit_price?: ?float}>  $services
     */
    private function attachServicesIfAny(Appointment $appointment, ?array $services): void
    {
        if (empty($services)) {
            return;
        }

        $appointment->update(['price' => $this->servicesWriter->sync($appointment, $services)]);
    }
}
