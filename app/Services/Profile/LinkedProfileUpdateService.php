<?php

namespace App\Services\Profile;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Atualiza o `Professional`/`Company` vinculado ao usuário autenticado a
 * partir dos blocos aninhados `professional`/`company` de `PUT /api/profile`.
 *
 * Sempre grava através da relação `hasOne` do próprio `$user` recebido —
 * nunca por um id vindo do payload. Isso torna estruturalmente impossível
 * este service alterar o `Professional`/`Company` de outro usuário, mesmo
 * que o cliente tente enviar um `id` ou `user_id` no bloco aninhado (que,
 * de todo modo, `UpdateProfileRequest` já descarta por não ter regra).
 */
final class LinkedProfileUpdateService
{
    /**
     * @param  array<string, mixed>|null  $professionalData
     * @param  array<string, mixed>|null  $companyData
     */
    public function update(User $user, ?array $professionalData, ?array $companyData): void
    {
        $this->updateProfessional($user, $professionalData);
        $this->updateCompany($user, $companyData);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function updateProfessional(User $user, ?array $data): void
    {
        if ($data === null) {
            return;
        }

        if ($user->professional === null) {
            throw ValidationException::withMessages([
                'professional' => ['Este usuário não possui um cadastro profissional para editar.'],
            ]);
        }

        $user->professional->update($data);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function updateCompany(User $user, ?array $data): void
    {
        if ($data === null) {
            return;
        }

        if ($user->company === null) {
            throw ValidationException::withMessages([
                'company' => ['Este usuário não possui um cadastro de empresa para editar.'],
            ]);
        }

        $user->company->update($data);
    }
}
