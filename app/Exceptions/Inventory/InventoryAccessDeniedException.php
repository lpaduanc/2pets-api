<?php

namespace App\Exceptions\Inventory;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * O item de estoque referenciado não pertence à organização (ou ao profissional autônomo) de
 * quem está registrando o ato — mesma regra de dono de `docs/vinculo-estoque-aplicacao-
 * clinica.md` item 5, aplicada também na baixa clínica, não só no CRUD de estoque.
 */
final class InventoryAccessDeniedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Você não tem acesso a este item de estoque.');
    }

    /** Resultado de negócio esperado, não incidente: não polui o log com stack trace. */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 403);
    }
}
