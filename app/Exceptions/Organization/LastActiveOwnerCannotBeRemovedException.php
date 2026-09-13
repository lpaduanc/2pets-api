<?php

namespace App\Exceptions\Organization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Uma organização nunca pode ficar sem nenhum `owner` ativo — desligar ou rebaixar o último
 * owner ativo travaria a própria organização por fora (ninguém mais poderia gerenciá-la).
 * Bloqueia tanto `DELETE .../members/{member}` quanto `PATCH .../members/{member}` com
 * `role` diferente de `owner` quando o alvo é o último owner ativo.
 */
final class LastActiveOwnerCannotBeRemovedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A organização precisa ter pelo menos um proprietário ativo. Promova outra pessoa antes de continuar.');
    }

    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
