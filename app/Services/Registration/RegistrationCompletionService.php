<?php

namespace App\Services\Registration;

use App\Enums\OrganizationRole;
use App\Enums\ProfessionalType;
use App\Models\Company;
use App\Models\Organization;
use App\Models\Professional;
use App\Models\User;
use App\Services\CrmvValidationService;
use App\Services\Organization\OrganizationInvitationService;
use App\Services\Organization\UserRoleReconciler;
use App\Support\Registration\ProfessionalCapabilityFieldExtractor;
use App\Support\Registration\ProfessionalCapabilityRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persiste os quatro fluxos de conclusão de cadastro (tutor, veterinário, profissional
 * genérico e empresa parceira). A validação já aconteceu no Form Request correspondente —
 * aqui só orquestra a gravação em `users` + tabela específica do tipo de conta.
 */
final class RegistrationCompletionService
{
    public function __construct(
        private readonly RegistrationAddressResolver $addressResolver,
        private readonly CrmvValidationService $crmvValidationService,
        private readonly BusinessOrganizationRegistrar $organizationRegistrar,
        private readonly UserRoleReconciler $roleReconciler,
        private readonly OrganizationInvitationService $invitationService,
    ) {}

    /** @param array<string, mixed> $data */
    public function completeTutor(User $user, array $data, ?UploadedFile $avatar): User
    {
        $user->update($this->tutorUserData($data));
        $this->addressResolver->scheduleRetryIfFailed($user);
        $this->attachAvatar($user, $avatar);

        return $user->fresh();
    }

    /** @param array<string, mixed> $data */
    public function completeVet(User $user, array $data): User
    {
        DB::transaction(function () use ($user, $data): void {
            $user->update($this->vetUserData($data));
            Professional::create($this->vetProfessionalData($user, $data));
        });
        $this->addressResolver->scheduleRetryIfFailed($user);

        return $user->fresh()->load('professional');
    }

    /**
     * Toda conta de negócio (clínica, laboratório, petshop, hotel, banho e tosa,
     * adestramento — nunca `vet`, ver `BusinessOrganizationRegistrar`) ganha uma
     * `Organization` própria com o representante como `owner`. É o caminho de ida que
     * faltava depois do backfill (`OrganizationBackfillService`, que só cobre contas
     * PRÉ-EXISTENTES): sem ele, conta de negócio nova nascia sem organização, sem `owner` e
     * sem nenhum jeito de gerenciar equipe.
     *
     * @param  array<string, mixed>  $data
     * @return array{user: User, organization: ?Organization, technical_responsible_invitation_sent: bool}
     */
    public function completeGenericProfessional(User $user, ProfessionalType $professionalType, array $data): array
    {
        $organization = DB::transaction(function () use ($user, $professionalType, $data): Organization {
            $user->update($this->genericProfessionalUserData($data));
            $this->addressResolver->scheduleRetryIfFailed($user);
            $professional = Professional::updateOrCreate(
                ['user_id' => $user->id],
                $this->genericProfessionalData($professionalType, $data)
            );

            $organization = $this->organizationRegistrar->register($user, $professionalType, $data, $professional);
            $this->roleReconciler->reconcile($user);

            return $organization;
        });

        return [
            'user' => $user->fresh()->load('professional'),
            'organization' => $organization,
            'technical_responsible_invitation_sent' => $this->inviteThirdPartyTechnicalResponsible($organization, $user, $data),
        ];
    }

    /**
     * Convite é efeito colateral não-crítico (mesma postura de `attachAvatar`): uma falha no
     * envio de e-mail não pode derrubar um cadastro que já foi persistido com sucesso.
     * Disparado FORA da transação de gravação, para não segurar lock de linha durante uma
     * chamada de rede.
     *
     * @param  array<string, mixed>  $data
     */
    private function inviteThirdPartyTechnicalResponsible(Organization $organization, User $representative, array $data): bool
    {
        if (($data['technical_responsible_is_self'] ?? true) !== false) {
            return false;
        }

        try {
            $this->invitationService->invite(
                $organization,
                $representative,
                $data['technical_responsible_email'],
                OrganizationRole::VETERINARIAN,
            );

            return true;
        } catch (Throwable $e) {
            $this->logInvitationFailure($organization, $representative, $e);

            return false;
        }
    }

    private function logInvitationFailure(Organization $organization, User $representative, Throwable $e): void
    {
        Log::warning('RegistrationCompletionService: Failed to send technical responsible invitation', [
            'organization_id' => $organization->id,
            'user_id' => $representative->id,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * DECISÃO (2026-09-16): empresa parceira não geocodifica, de propósito —
     * `users.latitude/longitude/location` ficam nulos após completar este cadastro, e não é
     * bug. `Company` é o cadastro do parceiro do Clube de Vantagens (CLAUDE.md módulo 9): ele
     * nunca aparece em `ProfessionalSearchService` nem em nenhuma busca por `ST_DWithin` — o
     * endereço aqui serve só para identificação/contrato, não para geolocalização. Os outros
     * três fluxos geocodificam porque alimentam a busca de profissionais por proximidade; este
     * não tem esse consumidor. Se um caso de uso geográfico para empresa parceira aparecer no
     * futuro, o caminho é `RegistrationAddressResolver` (mesmo usado pelos outros três), não
     * reinventar geocodificação aqui.
     *
     * @param  array<string, mixed>  $data
     */
    public function completeCompany(User $user, array $data): User
    {
        DB::transaction(function () use ($user, $data): void {
            $user->update($this->companyUserData($data));
            Company::updateOrCreate(['user_id' => $user->id], $this->companyData($data));
        });

        return $user->fresh()->load('company');
    }

    /** @return array<string, mixed> */
    private function tutorUserData(array $data): array
    {
        return [
            'cpf' => $data['cpf'],
            'birth_date' => $data['birth_date'],
            'gender' => $data['gender'] ?? null,
            'occupation' => $data['occupation'] ?? null,
            'profile_completed' => true,
            ...$this->addressResolver->addressData($data),
            ...$this->addressResolver->coordinatesPreferringRequest($data),
        ];
    }

    /** @return array<string, mixed> */
    private function vetUserData(array $data): array
    {
        return [
            'cpf' => $data['cpf'],
            'birth_date' => $data['birth_date'],
            'profile_completed' => true,
            ...$this->addressResolver->addressData($data),
            ...$this->addressResolver->geocodedCoordinates($data),
        ];
    }

    /** @return array<string, mixed> */
    private function vetProfessionalData(User $user, array $data): array
    {
        return [
            'user_id' => $user->id,
            'professional_type' => ProfessionalType::VET,
            'crmv' => $this->crmvValidationService->format($data['crmv'], $data['crmv_state']),
            'crmv_state' => $data['crmv_state'],
            'university' => $data['university'],
            'graduation_year' => $data['graduation_year'],
            'courses' => $data['courses'] ?? [],
            'specialties' => $data['specialties'] ?? [],
            'experience_years' => $data['experience_years'],
            'service_radius_km' => $data['service_radius_km'] ?? null,
            'opening_hours' => $data['opening_hours'],
            'closing_hours' => $data['closing_hours'],
            'working_days' => $data['working_days'],
            'description' => $data['description'] ?? null,
            'services_offered' => $data['services_offered'] ?? [],
            // Equipamento portátil (`docs/equipamento-vet-volante-e-marketplace-b2b.md` §2.1):
            // até 2026-09-13 `vet` tinha `equipment: prohibited` na matriz, então esta chave
            // nunca existia no payload validado — não havia nada para persistir. Agora que
            // `vet` pode declarar equipamento, precisa do mesmo tratamento que
            // `genericProfessionalData()` já dava para `clinic`/`laboratory`.
            'equipment' => $data['equipment'] ?? [],
            ...ProfessionalCapabilityFieldExtractor::extract($data, ProfessionalCapabilityRegistry::for(ProfessionalType::VET)),
        ];
    }

    /**
     * `cpf` aqui é do REPRESENTANTE (quem está completando o cadastro, futuro `owner` da
     * `Organization`) — nunca do responsável técnico, que tem seus próprios campos
     * `technical_responsible_*` (ver `BusinessOrganizationRegistrar`).
     *
     * @return array<string, mixed>
     */
    private function genericProfessionalUserData(array $data): array
    {
        return [
            'cpf' => $data['cpf'],
            'profile_completed' => true,
            ...$this->addressResolver->addressData($data),
            ...$this->addressResolver->geocodedCoordinates($data),
        ];
    }

    /** @return array<string, mixed> */
    private function genericProfessionalData(ProfessionalType $professionalType, array $data): array
    {
        return [
            'professional_type' => $professionalType,
            'business_name' => $data['business_name'],
            'cnpj' => $data['cnpj'],
            'opening_hours' => $data['opening_hours'],
            'closing_hours' => $data['closing_hours'],
            'working_days' => $data['working_days'],
            'description' => $data['description'] ?? null,
            'services_offered' => $data['services_offered'] ?? [],
            'products_sold' => $data['products_sold'] ?? [],
            'equipment' => $data['equipment'] ?? [],
            'certifications' => $data['certifications'] ?? [],
            // Atendimento móvel de banho e tosa/adestramento (docs/segmentacao-cadastro-
            // profissional.md §6): a coluna sempre existiu, o Form Request nunca a lia para
            // negócio genérico — `Professional::updateOrCreate` descartava em silêncio.
            'service_radius_km' => $data['service_radius_km'] ?? null,
            'technical_responsible_id' => $data['technical_responsible_id'] ?? null,
            'technical_responsible_name' => $data['technical_responsible_name'] ?? null,
            'technical_responsible_crmv' => $data['technical_responsible_crmv'] ?? null,
            'technical_responsible_crmv_state' => $data['technical_responsible_crmv_state'] ?? null,
            ...ProfessionalCapabilityFieldExtractor::extract($data, ProfessionalCapabilityRegistry::for($professionalType)),
        ];
    }

    /**
     * Conclusão de perfil é registrada em `profile_completed` e NUNCA em `registration_status`.
     *
     * O código legado gravava aqui `registration_status = 'completed'` — valor que nenhuma query
     * do sistema lê e que rebaixava silenciosamente uma empresa já `approved` pelo admin para um
     * estado que todo filtro `where('registration_status', 'approved')` exclui, entre eles
     * `ProfessionalSearchService::buildBaseQuery()` e `AdminController::pendingCompanies()`.
     * Resultado: completar o cadastro fazia a conta sumir das duas pontas do painel — nem
     * aprovada, nem na fila de aprovação.
     *
     * `registration_status` pertence ao ciclo de moderação (`pending` → `approved`/`rejected`),
     * decidido por admin. Ato do próprio usuário não altera decisão de moderação.
     *
     * @return array<string, mixed>
     */
    private function companyUserData(array $data): array
    {
        return [
            ...$this->addressResolver->addressData($data),
            'cnpj' => $data['cnpj'],
            'employee_count' => $data['employee_count'],
            'profile_completed' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function companyData(array $data): array
    {
        return [
            'company_name' => $data['company_name'],
            'cnpj' => $data['cnpj'],
            'contact_name' => $data['contact_name'],
            'contact_position' => $data['contact_position'] ?? null,
            'phone' => $data['phone'],
            'website' => $data['website'] ?? null,
            'employee_count' => $data['employee_count'],
            'benefit_type' => $data['benefit_type'],
            'notes' => $data['notes'] ?? null,
            'legal_representative_name' => $data['legal_representative_name'],
            'legal_representative_cpf' => $data['legal_representative_cpf'],
            'legal_representative_birth_date' => $data['legal_representative_birth_date'],
            'legal_representative_phone' => $data['legal_representative_phone'],
            'industry_sector' => $data['industry_sector'],
            'has_pet_policy' => $data['has_pet_policy'] ?? false,
            'estimated_pet_owners' => $data['estimated_pet_owners'] ?? null,
            'preferred_communication' => $data['preferred_communication'] ?? null,
            'budget_range' => $data['budget_range'] ?? null,
            'start_date_preference' => $data['start_date_preference'] ?? null,
            'interested_services' => $data['interested_services'] ?? [],
        ];
    }

    /**
     * Não-bloqueante: uma falha no upload não deve interromper o cadastro, só é logada.
     */
    private function attachAvatar(User $user, ?UploadedFile $avatar): void
    {
        if ($avatar === null) {
            return;
        }

        try {
            $user->addMedia($avatar)->toMediaCollection('avatar');
        } catch (Throwable $e) {
            Log::warning('RegistrationCompletionService: Failed to store tutor avatar', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
