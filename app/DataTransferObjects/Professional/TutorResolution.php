<?php

namespace App\DataTransferObjects\Professional;

use App\Models\User;

/**
 * Resultado de `TutorIdentityResolver::resolve()` — o tutor encontrado/criado, mais os dois
 * fatos que o restante do fluxo (e o e-mail que sai no fim) precisam saber sem reconsultar o
 * banco: se a conta é nova e se um e-mail informado foi descartado por já pertencer a outro CPF.
 */
final readonly class TutorResolution
{
    public function __construct(
        public User $user,
        public bool $isNewAccount,
        public bool $emailIgnoredDueToConflict,
    ) {}
}
