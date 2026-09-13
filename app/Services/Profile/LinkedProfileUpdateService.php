<?php

namespace App\Services\Profile;

use App\Models\User;
use App\Support\Registration\ProfessionalCapabilityFieldExtractor;
use App\Support\Registration\ProfessionalCapabilityRegistry;
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

        $user->professional->update($this->withCapabilityFieldsScrubbed($user, $data));
    }

    /**
     * Segunda camada de defesa (a mesma de `RegistrationCompletionService`, ver
     * `ProfessionalCapabilityFieldExtractor::scrubForPatch()`): a validação aceita
     * `false`/`0`/vazio num campo de capacidade não aplicável ao tipo (de propósito, ver
     * `App\Rules\ProhibitedCapabilityValue`), mas a coluna correspondente nunca deve ser
     * escrita para esse tipo — neutraliza só as chaves que este PATCH realmente enviou.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withCapabilityFieldsScrubbed(User $user, array $data): array
    {
        $capabilities = ProfessionalCapabilityRegistry::for($user->professional->professional_type);

        return ProfessionalCapabilityFieldExtractor::scrubForPatch($data, $capabilities);
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
