<?php

namespace App\Exceptions\Hospitalization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md §1.3: uma entrada de
 * evolução diária, uma vez criada, nunca é editada nem apagada — sem exceção. A ausência de
 * rota de `update`/`destroy` já impede isso pela API, mas a trava real fica no MODEL
 * (`HospitalizationProgressNote::booted()`), para que nenhum caminho de escrita futuro
 * (`tinker`, job, outro service) consiga reescrever uma entrada já gravada por engano.
 * Correção é sempre uma entrada NOVA com `corrects_id` apontando para a original.
 */
final class HospitalizationProgressNoteImmutableException extends RuntimeException
{
    public static function forUpdate(): self
    {
        return new self('Uma entrada de evolução já registrada não pode ser editada — crie uma nova entrada com "corrects_id" apontando para ela.');
    }

    public static function forDelete(): self
    {
        return new self('Uma entrada de evolução já registrada não pode ser apagada.');
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
