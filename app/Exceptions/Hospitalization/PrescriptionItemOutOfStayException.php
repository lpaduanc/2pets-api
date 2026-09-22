<?php

namespace App\Exceptions\Hospitalization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Regra de negócio 3 da spec docs/gap-simplesvet/specs/12-internacao-mapa-execucao-spec.md:
 * `starts_at` de um item de prescrição de internação não pode ser anterior à admissão nem
 * posterior à alta (quando já houver).
 */
final class PrescriptionItemOutOfStayException extends RuntimeException
{
    public static function beforeAdmission(): self
    {
        return new self('O início da medicação não pode ser anterior à data de admissão da internação.');
    }

    public static function afterDischarge(): self
    {
        return new self('O início da medicação não pode ser posterior à data de alta da internação.');
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
