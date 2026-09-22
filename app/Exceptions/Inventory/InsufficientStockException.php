<?php

namespace App\Exceptions\Inventory;

use App\DataTransferObjects\LockedClinicalStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * O saldo do produto (ou lote) vinculado não cobre a baixa de 1 unidade. Nunca bloqueia o
 * registro do ato clínico em si — só a vinculação a ESTE item de estoque
 * (docs/vinculo-estoque-aplicacao-clinica.md item 1). O profissional pode reenviar sem
 * `product_id` para registrar mesmo assim; o `code` deixa o app mostrar uma mensagem não
 * técnica, nunca o texto cru do backend.
 */
final class InsufficientStockException extends RuntimeException
{
    public function __construct(LockedClinicalStock $lock)
    {
        parent::__construct(
            "Estoque insuficiente para \"{$lock->product->name}\". Ajuste o estoque ou registre sem vincular a um item."
        );
    }

    /** Resultado de negócio esperado, não incidente: não polui o log com stack trace. */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'insufficient_stock',
        ], 422);
    }
}
