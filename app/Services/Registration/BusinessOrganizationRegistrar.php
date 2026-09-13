<?php

namespace App\Services\Registration;

use App\Enums\OrganizationType;
use App\Enums\ProfessionalType;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Professional;
use App\Models\User;
use App\Services\CrmvValidationService;
use App\Services\Organization\VeterinarianCrmvGuard;

/**
 * Cria/atualiza a `Organization` de uma conta de negócio no momento em que ela completa o
 * cadastro (`RegistrationCompletionService::completeGenericProfessional`) — o caminho de ida
 * que faltava: `OrganizationBackfillService` só cobre contas PRÉ-EXISTENTES, nunca foi ligado
 * ao fluxo de cadastro novo.
 *
 * Vet volante (`ProfessionalType::VET`) nunca passa por aqui — quem decide isso é o chamador,
 * que só invoca este serviço para tipos presentes em `OrganizationType`.
 *
 * Idempotente: uma segunda chamada para o mesmo representante atualiza a organização que ele
 * já possui como `owner`, em vez de criar uma segunda (mesmo critério de idempotência do
 * `OrganizationBackfillService`: "este usuário já é owner de alguma organização?").
 */
final class BusinessOrganizationRegistrar
{
    public function __construct(
        private readonly VeterinarianCrmvGuard $crmvGuard,
        private readonly CrmvValidationService $crmvValidationService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(User $representative, ProfessionalType $professionalType, array $data, Professional $professional): Organization
    {
        $organization = $this->resolveOrganization($representative);
        $organization->fill($this->organizationData($professionalType, $data, $representative));
        $organization->save();

        $this->ensureOwnerMembership($organization, $representative);
        $this->assignTechnicalResponsible($organization, $representative, $professional, $data);

        return $organization->fresh();
    }

    private function resolveOrganization(User $representative): Organization
    {
        return $representative->ownedOrganizations()->first() ?? new Organization;
    }

    /** @return array<string, mixed> */
    private function organizationData(ProfessionalType $professionalType, array $data, User $representative): array
    {
        return [
            'organization_type' => OrganizationType::from($professionalType->value),
            'business_name' => $data['business_name'],
            'cnpj' => $data['cnpj'],
            'description' => $data['description'] ?? null,
            'opening_hours' => $data['opening_hours'],
            'closing_hours' => $data['closing_hours'],
            'working_days' => $data['working_days'],
            // Coluna existia desde a criação de `organizations` mas nunca era populada para
            // negócio genérico (docs/segmentacao-cadastro-profissional.md §6) — banho e tosa
            // móvel/adestramento a domicílio, opcional para os dois, sempre `null` para os
            // demais tipos (Form Request já garante isso via `HasProfessionalCapabilityRules`).
            'service_radius_km' => $data['service_radius_km'] ?? null,
            'services_offered' => $data['services_offered'] ?? [],
            'products_sold' => $data['products_sold'] ?? [],
            ...$this->addressFrom($representative),
        ];
    }

    /** @return array<string, mixed> */
    private function addressFrom(User $representative): array
    {
        return [
            'address' => $representative->address,
            'number' => $representative->number,
            'complement' => $representative->complement,
            'neighborhood' => $representative->neighborhood,
            'city' => $representative->city,
            'state' => $representative->state,
            'zip_code' => $representative->zip_code,
            'latitude' => $representative->latitude,
            'longitude' => $representative->longitude,
        ];
    }

    private function ensureOwnerMembership(Organization $organization, User $representative): void
    {
        OrganizationMember::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $representative->id,
                'role' => OrganizationMember::ROLE_OWNER,
            ],
            [
                'hire_date' => $representative->created_at?->toDateString() ?? now()->toDateString(),
                'is_active' => true,
            ]
        );
    }

    /**
     * Sem `technical_responsible_is_self` no payload, o tipo de negócio não exige RT
     * (`CompleteGenericProfessionalRegistrationRequest::requiresTechnicalResponsible`) — nada
     * a fazer.
     *
     * @param  array<string, mixed>  $data
     */
    private function assignTechnicalResponsible(Organization $organization, User $representative, Professional $professional, array $data): void
    {
        if (! isset($data['technical_responsible_is_self'])) {
            return;
        }

        if ($data['technical_responsible_is_self']) {
            $this->assignSelfAsTechnicalResponsible($organization, $representative, $professional, $data);

            return;
        }

        // Terceiro convidado por e-mail: os campos de texto são gravados como fallback até o
        // convite ser aceito (`OrganizationInvitationService`, disparado pelo chamador).
        $organization->update([
            'technical_responsible_name' => $data['technical_responsible_name'],
            'technical_responsible_crmv' => $data['technical_responsible_crmv'],
            'technical_responsible_crmv_state' => $data['technical_responsible_crmv_state'],
            'technical_responsible_verified' => false,
        ]);
    }

    /**
     * O representante É o RT: seu próprio `Professional` ganha o CRMV informado, a
     * organização aponta para ele por FK (preferível ao texto livre) e o vínculo ganha o
     * cargo `veterinarian` — respeitando o mesmo `VeterinarianCrmvGuard` que protege o aceite
     * de convite e a troca de cargo (nunca um segundo caminho de validação).
     *
     * @param  array<string, mixed>  $data
     */
    private function assignSelfAsTechnicalResponsible(Organization $organization, User $representative, Professional $professional, array $data): void
    {
        $this->grantCrmvToRepresentative($representative, $professional, $data);
        $this->syncOrganizationToSelfTechnicalResponsible($organization, $representative, $professional);
        $this->ensureVeterinarianMembership($organization, $representative);
    }

    /** @param array<string, mixed> $data */
    private function grantCrmvToRepresentative(User $representative, Professional $professional, array $data): void
    {
        $professional->update([
            'crmv' => $this->crmvValidationService->format($data['technical_responsible_crmv'], $data['technical_responsible_crmv_state']),
            'crmv_state' => $data['technical_responsible_crmv_state'],
        ]);

        $representative->setRelation('professional', $professional);
        $this->crmvGuard->ensureHasCrmv($representative);
    }

    private function syncOrganizationToSelfTechnicalResponsible(Organization $organization, User $representative, Professional $professional): void
    {
        $organization->update([
            'technical_responsible_professional_id' => $professional->id,
            'technical_responsible_name' => $representative->name,
            'technical_responsible_crmv' => $professional->crmv,
            'technical_responsible_crmv_state' => $professional->crmv_state,
            'technical_responsible_verified' => false,
        ]);
    }

    private function ensureVeterinarianMembership(Organization $organization, User $representative): void
    {
        OrganizationMember::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $representative->id,
                'role' => OrganizationMember::ROLE_VETERINARIAN,
            ],
            [
                'hire_date' => $representative->created_at?->toDateString() ?? now()->toDateString(),
                'is_active' => true,
            ]
        );
    }
}
