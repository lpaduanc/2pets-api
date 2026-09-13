<?php

namespace App\Services\Registration;

use App\Contracts\RegistrationDraftRepository;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Orquestra os dois lados de um rascunho de cadastro: cache (restauração rápida do formulário
 * inteiro, inclusive campos que ainda não têm coluna no banco) e banco (persistência
 * progressiva do que já é um dado estruturado). Genérico por design — o `RegistrationDraftRepository`
 * injetado em cada chamada é quem sabe em quais tabelas gravar; esta classe nunca conhece
 * `Professional`/`Company`/`User` diretamente.
 */
final class RegistrationDraftService
{
    private const CACHE_TTL_DAYS = 7;

    /**
     * @param  array<string, mixed>  $rawPayload  corpo bruto da requisição — cacheado como
     *                                            veio, sem validação, porque inclui estado do wizard (ex.: `current_step`) que
     *                                            nunca vira coluna de banco.
     * @param  array<string, mixed>  $validatedPayload  saída do Form Request da rota, é o que
     *                                                  de fato é gravado nas tabelas.
     * @return array<string, mixed>
     */
    public function save(
        RegistrationDraftRepository $repository,
        User $user,
        array $rawPayload,
        array $validatedPayload
    ): array {
        Cache::put($this->cacheKey($repository, $user), $rawPayload, now()->addDays(self::CACHE_TTL_DAYS));

        $repository->persist($user, $validatedPayload);

        return [
            'success' => true,
            'message' => 'Rascunho salvo com sucesso',
            'saved_at' => now()->toIso8601String(),
            'saved_to_database' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function load(RegistrationDraftRepository $repository, User $user): array
    {
        $draft = Cache::get($this->cacheKey($repository, $user));
        $existingData = $repository->fetch($user);
        $mergedData = $draft === null ? $existingData : [...$existingData, ...$draft];

        return [
            'success' => true,
            'draft' => $mergedData,
            'has_draft' => $mergedData !== [],
            'has_database_data' => $existingData !== [],
            'source' => $draft === null ? 'database_only' : 'draft_and_database',
        ];
    }

    public function delete(RegistrationDraftRepository $repository, User $user): void
    {
        Cache::forget($this->cacheKey($repository, $user));
    }

    private function cacheKey(RegistrationDraftRepository $repository, User $user): string
    {
        return "registration_draft_{$repository->cacheKeyPrefix()}_{$user->id}";
    }
}
