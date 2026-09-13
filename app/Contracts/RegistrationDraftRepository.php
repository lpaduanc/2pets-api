<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Persistência progressiva de um rascunho de cadastro (tutor/profissional/empresa). Cada
 * tipo de conta ganha sua própria implementação — nova jornada de cadastro (ex.: um quarto
 * tipo de conta) é uma nova classe implementando este contrato, nunca um `match` a mais em
 * `RegistrationDraftService` (OCP).
 */
interface RegistrationDraftRepository
{
    /** Prefixo usado na chave de cache (`registration_draft_{prefixo}_{user_id}`). */
    public function cacheKeyPrefix(): string;

    /** @return array<string, mixed> */
    public function fetch(User $user): array;

    /** @param  array<string, mixed>  $data  já validado pelo Form Request da rota */
    public function persist(User $user, array $data): void;
}
