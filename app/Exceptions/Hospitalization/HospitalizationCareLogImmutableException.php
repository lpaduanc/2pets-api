<?php

namespace App\Exceptions\Hospitalization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Mesmo desenho de `HospitalizationProgressNoteImmutableException` — o checklist de cuidados
 * é log de eventos (contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md §4),
 * não um formulário revisável: um cuidado marcado "não feito" com justificativa é o registro
 * do que de fato aconteceu, e reescrevê-lo depois apagaria o rastro que o §4.3 existe para
 * preservar.
 */
final class HospitalizationCareLogImmutableException extends RuntimeException
{
    public static function forUpdate(): self
    {
        return new self('Um registro de cuidado já lançado não pode ser editado.');
    }

    public static function forDelete(): self
    {
        return new self('Um registro de cuidado já lançado não pode ser apagado.');
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
