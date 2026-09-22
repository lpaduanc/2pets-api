<?php

namespace App\Services\Import;

use App\Models\ProfessionalClient;
use App\Models\User;

/**
 * Acha o tutor de um pet/vacina importados (item 26 do backlog gap-simplesvet) — "vinculado a
 * cliente já importado/existente" da spec exige mais do que achar QUALQUER `User` com o
 * CPF/e-mail/telefone informado (`ClientDuplicateDetector` sozinho faria isso): exige que o
 * cliente encontrado seja de fato cliente DESTE profissional (`ProfessionalClient`), senão o
 * pet de um tutor de outro dono entraria vinculado por coincidência de CPF.
 */
final class PetTutorResolver
{
    public function __construct(private readonly ClientDuplicateDetector $clientDuplicateDetector) {}

    /**
     * @param  array{cpf: ?string, email: ?string, phone: ?string}  $tutorIdentifiers
     */
    public function resolve(array $tutorIdentifiers, int $professionalId): ?User
    {
        $client = $this->clientDuplicateDetector->findExisting($tutorIdentifiers);

        if ($client === null) {
            return null;
        }

        return $this->isClientOfProfessional($client, $professionalId) ? $client : null;
    }

    private function isClientOfProfessional(User $client, int $professionalId): bool
    {
        return ProfessionalClient::where('professional_id', $professionalId)
            ->where('client_id', $client->id)
            ->exists();
    }
}
