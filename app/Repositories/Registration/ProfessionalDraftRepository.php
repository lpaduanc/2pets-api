<?php

namespace App\Repositories\Registration;

use App\Contracts\RegistrationDraftRepository;
use App\Models\Professional;
use App\Models\User;
use App\Support\Registration\Draft\DraftDocumentsReader;
use App\Support\Registration\Draft\DraftFieldExtractor;
use Illuminate\Support\Facades\DB;

/**
 * Rascunho de cadastro de profissional (vet volante, clínica, petshop, etc.). Grava em duas
 * tabelas (`professionals` + `users`) — a segmentação por `professional_type` continua vindo
 * só de `$user->user_type` (nunca do payload), mesma regra que
 * `docs/taxonomia-professional-type.md` fixou para o fluxo de conclusão de cadastro.
 */
final class ProfessionalDraftRepository implements RegistrationDraftRepository
{
    /** @var array<string, string> coluna de `professionals` => chave do payload validado */
    private const PROFESSIONAL_FIELD_MAP = [
        'business_name' => 'business_name',
        'cnpj' => 'cnpj',
        'website' => 'website',
        'crmv' => 'crmv',
        'crmv_state' => 'crmv_state',
        'university' => 'university',
        'graduation_year' => 'graduation_year',
        'experience_years' => 'experience_years',
        'specialties' => 'specialties',
        'courses' => 'courses',
        'service_radius_km' => 'service_radius_km',
        'opening_hours' => 'opening_hours',
        'closing_hours' => 'closing_hours',
        'working_days' => 'working_days',
        'description' => 'description',
        'technical_responsible_name' => 'technical_responsible_name',
        'technical_responsible_crmv' => 'technical_responsible_crmv',
        'technical_responsible_crmv_state' => 'technical_responsible_crmv_state',
        'services_offered' => 'services_offered',
        'products_sold' => 'products_sold',
        'equipment' => 'equipment',
        'certifications' => 'certifications',
    ];

    /** @var array<string, string> coluna de `users` => chave do payload validado */
    private const USER_FIELD_MAP = [
        'cpf' => 'cpf',
        'birth_date' => 'birth_date',
        'phone' => 'professional_phone',
        'address' => 'address',
        'number' => 'number',
        'complement' => 'complement',
        'neighborhood' => 'neighborhood',
        'city' => 'city',
        'state' => 'state',
        'zip_code' => 'zip_code',
    ];

    private const DEFAULT_WORKING_DAYS = [1, 2, 3, 4, 5];

    public function __construct(private readonly DraftDocumentsReader $documentsReader) {}

    public function cacheKeyPrefix(): string
    {
        return 'professional';
    }

    public function persist(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data): void {
            $this->persistProfessionalFields($user, $data);
            $this->persistUserFields($user, $data);
        });
    }

    /** @return array<string, mixed> */
    public function fetch(User $user): array
    {
        $professional = Professional::where('user_id', $user->id)->first();

        return DraftFieldExtractor::withoutEmptyValues([
            ...($professional === null ? [] : $this->professionalData($professional)),
            ...$this->userData($user),
            ...$this->documentsData($user),
        ]);
    }

    /**
     * CNPJ/CRMV de outro usuário é barrado por `Rule::unique` no
     * `SaveProfessionalDraftRequest` (e, numa corrida entre duas requisições, pelo índice
     * único parcial do banco + o handler global de `QueryException`) — nunca mais apagado
     * aqui, ver `correcao-cadastro-onda1-2026-09-13.md`.
     */
    private function persistProfessionalFields(User $user, array $data): void
    {
        $professionalData = DraftFieldExtractor::extract($data, self::PROFESSIONAL_FIELD_MAP);

        if ($professionalData === []) {
            return;
        }

        $existing = Professional::where('user_id', $user->id)->first();

        if ($existing !== null) {
            $existing->update($professionalData);

            return;
        }

        Professional::create([
            ...$professionalData,
            'user_id' => $user->id,
            'professional_type' => $user->user_type,
        ]);
    }

    private function persistUserFields(User $user, array $data): void
    {
        $userData = DraftFieldExtractor::extract($data, self::USER_FIELD_MAP);

        if ($userData !== []) {
            $user->update($userData);
        }
    }

    /** @return array<string, mixed> */
    private function professionalData(Professional $professional): array
    {
        return [
            'business_name' => $professional->business_name,
            'cnpj' => $professional->cnpj,
            'website' => $professional->website,
            'crmv' => $professional->crmv,
            'crmv_state' => $professional->crmv_state,
            'university' => $professional->university,
            'graduation_year' => $professional->graduation_year,
            'experience_years' => $professional->experience_years,
            'specialties' => $professional->specialties ?? [],
            'courses' => $professional->courses ?? [],
            'service_radius_km' => $professional->service_radius_km,
            'opening_hours' => $professional->opening_hours,
            'closing_hours' => $professional->closing_hours,
            'working_days' => $professional->working_days ?? self::DEFAULT_WORKING_DAYS,
            'description' => $professional->description,
            'technical_responsible_name' => $professional->technical_responsible_name,
            'technical_responsible_crmv' => $professional->technical_responsible_crmv,
            'technical_responsible_crmv_state' => $professional->technical_responsible_crmv_state,
            'services_offered' => $professional->services_offered ?? [],
            'products_sold' => $professional->products_sold ?? [],
            'equipment' => $professional->equipment ?? [],
            'certifications' => $professional->certifications ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function userData(User $user): array
    {
        return [
            'cpf' => $user->cpf,
            'birth_date' => $user->birth_date,
            'professional_phone' => $user->phone,
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
