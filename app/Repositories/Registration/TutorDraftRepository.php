<?php

namespace App\Repositories\Registration;

use App\Contracts\RegistrationDraftRepository;
use App\Models\User;
use App\Support\Registration\Draft\DraftDocumentsReader;
use App\Support\Registration\Draft\DraftFieldExtractor;
use Illuminate\Support\Facades\DB;

/**
 * Rascunho de cadastro de tutor. Único dos 3 fluxos que grava só em `users` — não há tabela
 * própria de tutor.
 */
final class TutorDraftRepository implements RegistrationDraftRepository
{
    /** @var array<string, string> coluna de `users` => chave do payload validado */
    private const USER_FIELD_MAP = [
        'cpf' => 'cpf',
        'birth_date' => 'birth_date',
        'gender' => 'gender',
        'occupation' => 'occupation',
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
        return 'tutor';
    }

    public function persist(User $user, array $data): void
    {
        $userData = DraftFieldExtractor::extract($data, self::USER_FIELD_MAP);

        if ($userData === []) {
            return;
        }

        DB::transaction(fn () => $user->update($userData));
    }

    /** @return array<string, mixed> */
    public function fetch(User $user): array
    {
        return DraftFieldExtractor::withoutEmptyValues([
            ...$this->userData($user),
            ...$this->documentsData($user),
        ]);
    }

    /** @return array<string, mixed> */
    private function userData(User $user): array
    {
        return [
            'cpf' => $user->cpf,
            'birth_date' => $user->birth_date,
            'gender' => $user->gender,
            'occupation' => $user->occupation,
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
