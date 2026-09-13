<?php

namespace App\Repositories\Registration;

use App\Contracts\RegistrationDraftRepository;
use App\Models\Company;
use App\Models\User;
use App\Support\Registration\Draft\DraftDocumentsReader;
use App\Support\Registration\Draft\DraftFieldExtractor;
use Illuminate\Support\Facades\DB;

/**
 * Rascunho de cadastro de empresa parceira (Clube de Vantagens). Grava em duas tabelas
 * (`companies` + `users`) — endereço é sempre coluna de `users`, nunca de `companies`
 * (que não tem colunas de endereço, ver `correcao-cadastro-onda2-2026-09-13.md`).
 *
 * **Achado corrigido nesta classe:** a versão anterior (`RegistrationDraftController`) lia/gravava
 * `company_address`, `company_phone`, `company_email`, `company_website`, `company_number`,
 * `company_complement`, `company_neighborhood`, `company_city`, `company_state`,
 * `company_zip_code` como se fossem colunas de `companies` — nenhuma delas existe na tabela
 * (confirmado via `\d companies`). Na leitura, Eloquent devolvia `null` em silêncio para cada
 * uma (nunca um erro), então o rascunho de empresa nunca restaurou endereço nenhum. Na escrita,
 * como nenhum desses nomes chega a aparecer no payload real (o formulário usa os campos
 * canônicos `address`/`number`/.../`zip_code`, os mesmos que `HasAddressRules` valida nos
 * outros 3 fluxos), o bloco de escrita nunca disparava — ficou puramente morto. Removido; o
 * endereço agora é lido/gravado em `users`, mesmo padrão dos outros fluxos de rascunho.
 */
final class CompanyDraftRepository implements RegistrationDraftRepository
{
    /** @var array<string, string> coluna de `companies` => chave do payload validado */
    private const COMPANY_FIELD_MAP = [
        'company_name' => 'company_name',
        'cnpj' => 'cnpj',
        'contact_name' => 'contact_name',
        'contact_position' => 'contact_position',
        'phone' => 'phone',
        'website' => 'website',
        'employee_count' => 'employee_count',
        'benefit_type' => 'benefit_type',
        'notes' => 'additional_notes',
        'legal_representative_name' => 'legal_representative_name',
        'legal_representative_cpf' => 'legal_representative_cpf',
        'legal_representative_birth_date' => 'legal_representative_birth_date',
        'legal_representative_phone' => 'legal_representative_phone',
        'industry_sector' => 'industry_sector',
        'has_pet_policy' => 'has_pet_policy',
        'estimated_pet_owners' => 'estimated_pet_owners',
        'preferred_communication' => 'preferred_communication',
        'budget_range' => 'budget_range',
        'start_date_preference' => 'start_date_preference',
        'interested_services' => 'interested_services',
    ];

    /** @var array<string, string> coluna de `users` => chave do payload validado */
    private const USER_FIELD_MAP = [
        'cnpj' => 'cnpj',
        'employee_count' => 'employee_count',
        'additional_notes' => 'additional_notes',
        'address' => 'address',
        'number' => 'number',
        'complement' => 'complement',
        'neighborhood' => 'neighborhood',
        'city' => 'city',
        'state' => 'state',
        'zip_code' => 'zip_code',
    ];

    public function __construct(private readonly DraftDocumentsReader $documentsReader) {}

    public function cacheKeyPrefix(): string
    {
        return 'company';
    }

    public function persist(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data): void {
            $this->persistCompanyFields($user, $data);
            $this->persistUserFields($user, $data);
        });
    }

    /**
     * `users` é a base (cobre o lead B2B que ainda não tem linha em `companies` — CNPJ
     * gravado na etapa 1, `AuthController::register()`); `companies` sobrescreve quando já
     * existe, porque é a fonte mais recente para os campos que os dois lados compartilham
     * (`cnpj`, `employee_count`, `additional_notes`) — mesma prioridade que o código anterior
     * expressava com `$data['cnpj'] ?? $user->cnpj`. Endereço só existe do lado de `users`.
     *
     * @return array<string, mixed>
     */
    public function fetch(User $user): array
    {
        $company = Company::where('user_id', $user->id)->first();

        return DraftFieldExtractor::withoutEmptyValues([
            ...$this->userData($user),
            ...($company === null ? [] : $this->companyData($company)),
            ...$this->documentsData($user),
        ]);
    }

    private function persistCompanyFields(User $user, array $data): void
    {
        $companyData = DraftFieldExtractor::extract($data, self::COMPANY_FIELD_MAP);

        if ($companyData === []) {
            return;
        }

        Company::updateOrCreate(['user_id' => $user->id], $companyData);
    }

    private function persistUserFields(User $user, array $data): void
    {
        $userData = DraftFieldExtractor::extract($data, self::USER_FIELD_MAP);

        if ($userData !== []) {
            $user->update($userData);
        }
    }

    /** @return array<string, mixed> */
    private function companyData(Company $company): array
    {
        return [
            'company_name' => $company->company_name,
            'cnpj' => $company->cnpj,
            'contact_name' => $company->contact_name,
            'contact_position' => $company->contact_position,
            'phone' => $company->phone,
            'website' => $company->website,
            'employee_count' => $company->employee_count,
            'benefit_type' => $company->benefit_type,
            'additional_notes' => $company->notes,
            'legal_representative_name' => $company->legal_representative_name,
            'legal_representative_cpf' => $company->legal_representative_cpf,
            'legal_representative_birth_date' => $company->legal_representative_birth_date,
            'legal_representative_phone' => $company->legal_representative_phone,
            'industry_sector' => $company->industry_sector?->value,
            'has_pet_policy' => $company->has_pet_policy,
            'estimated_pet_owners' => $company->estimated_pet_owners?->value,
            'preferred_communication' => $company->preferred_communication?->value,
            'budget_range' => $company->budget_range?->value,
            'start_date_preference' => $company->start_date_preference?->toDateString(),
            'interested_services' => $company->interested_services,
        ];
    }

    /** @return array<string, mixed> */
    private function userData(User $user): array
    {
        return [
            'cnpj' => $user->cnpj,
            'employee_count' => $user->employee_count,
            'additional_notes' => $user->additional_notes,
            'address' => $user->address,
            'number' => $user->number,
            'complement' => $user->complement,
            'neighborhood' => $user->neighborhood,
            'city' => $user->city,
            'state' => $user->state,
            'zip_code' => $user->zip_code,
        ];
    }

    /** @return array<string, mixed> */
    private function documentsData(User $user): array
    {
        $documents = $this->documentsReader->forUser($user);

        return $documents === [] ? [] : ['documents' => $documents];
    }
}
