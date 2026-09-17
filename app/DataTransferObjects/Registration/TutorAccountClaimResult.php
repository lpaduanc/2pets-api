<?php

namespace App\DataTransferObjects\Registration;

use App\Models\User;

/**
 * Resultado de `TutorAccountClaimService::resolve()`.
 *
 * `newAccessToken` só vem preenchido quando `claimed` é verdadeiro: a conta autenticada na
 * requisição (a "casca") foi soft-deletada, então o token Sanctum usado para chegar até aqui
 * para de funcionar em qualquer requisição futura — o controller precisa devolver um token novo
 * para a pessoa não cair deslogada no meio do próprio cadastro.
 */
final readonly class TutorAccountClaimResult
{
    public function __construct(
        public User $user,
        public bool $claimed,
        public ?string $newAccessToken,
    ) {}
}
