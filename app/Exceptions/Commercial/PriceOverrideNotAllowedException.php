<?php

namespace App\Exceptions\Commercial;

use App\Contracts\Sellable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Preço diferente do cadastrado num item com `allow_price_override = false` — critério de
 * aceite do doc 08 ("rejeita preço diferente no PDV (422)").
 *
 * A trava existe para o item de preço regulado (medicamento tabelado, serviço de convênio),
 * onde negociar no balcão é problema, não flexibilidade.
 */
final class PriceOverrideNotAllowedException extends RuntimeException
{
    public function __construct(private readonly Sellable $sellable, private readonly float $attempted)
    {
        parent::__construct(sprintf(
            '"%s" não permite alteração de preço na venda. Preço cadastrado: R$ %s.',
            $sellable->sellableName(),
            number_format($sellable->sellableUnitPrice(), 2, ',', '.')
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
            'code' => 'price_override_not_allowed',
            'expected_price' => $this->sellable->sellableUnitPrice(),
            'attempted_price' => $this->attempted,
        ], 422);
    }
}
