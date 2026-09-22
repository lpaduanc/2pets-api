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
        // Orçamento fora de rascunho cai aqui também (doc 24): a mensagem fala a língua do
        // orçamento e aponta a saída, que é revisar — não "a venda está paga".
        parent::__construct($sale->isQuote()
            ? sprintf(
                'Orçamento %s não pode mais ser alterado (situação: %s). Crie uma revisão.',
                $sale->number ?? $sale->id,
                mb_strtolower((string) $sale->effectiveQuoteStatus()?->label())
            )
            : sprintf(
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
            'code' => $this->sale->isQuote() ? 'quote_not_editable' : 'sale_not_editable',
            'status' => $this->sale->status->value,
            'quote_status' => $this->sale->effectiveQuoteStatus()?->value,
        ], 422);
    }
}
