<?php

namespace App\Exceptions\Hospitalization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Regra de negócio 1 da spec docs/gap-simplesvet/specs/12-internacao-mapa-execucao-spec.md:
 * um box só aceita uma internação `active` por vez.
 */
final class HospitalizationBoxOccupiedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Este box já está ocupado por outra internação ativa.');
    }

    /** Resultado de negócio esperado, não incidente: não polui o log com stack trace. */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
