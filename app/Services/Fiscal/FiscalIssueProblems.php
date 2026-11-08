<?php

namespace App\Services\Fiscal;

use Illuminate\Validation\ValidationException;

/**
 * Junta TUDO o que impede a emissão (sem perfil, sem NCM, sem CFOP, sem regime…) para devolver
 * um 422 só, com a lista — o dono corrige de uma vez em vez de descobrir um erro por tentativa.
 * Resposta: `{message, errors: {fiscal: [..]}}`.
 */
final class FiscalIssueProblems
{
    /** @var array<string, true> */
    private array $messages = [];

    public function add(string $message): void
    {
        $this->messages[$message] = true;
    }

    public function throwIfAny(): void
    {
        if ($this->messages === []) {
            return;
        }

        throw ValidationException::withMessages(['fiscal' => array_keys($this->messages)]);
    }
}
