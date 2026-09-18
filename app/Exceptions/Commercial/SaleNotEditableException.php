<?php

namespace App\Exceptions\Commercial;

use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Tentativa de mexer numa venda já paga ou cancelada. Venda paga é documento: a correção é um
 * estorno (doc 07, `sale_returns`), nunca a edição do original — mesma disciplina do prontuário
 * finalizado e do lançamento de estoque.
 */
final class SaleNotEditableException extends RuntimeException
{
    public function __construct(private readonly Sale $sale)
    {
        parent::__construct(sprintf(
            'Venda %s não pode mais ser alterada (situação: %s).',
            $sale->number ?? $sale->id,
            mb_strtolower($sale->status->label())
        ));
    }

    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'sale_not_editable',
            'status' => $this->sale->status->value,
        ], 422);
    }
}
