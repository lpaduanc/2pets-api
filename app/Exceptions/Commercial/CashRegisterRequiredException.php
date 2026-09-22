<?php

namespace App\Exceptions\Commercial;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Tentativa de vender ou receber sem caixa aberto — regra do doc 01 ("venda só entra em caixa
 * aberto").
 *
 * Diferente de `CashRegisterClosedException`: lá existe um caixa, só que fechado; aqui a pessoa
 * não tem caixa nenhum. O `code` separado deixa o app oferecer o botão "Abrir caixa" em vez de
 * mostrar só o erro.
 */
final class CashRegisterRequiredException extends RuntimeException
{
    public function __construct(string $action = 'vender')
    {
        parent::__construct(sprintf('Abra um caixa antes de %s.', $action));
    }

    /** Resultado de negócio esperado, não incidente: fora do log de erro. */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'cash_register_required',
        ], 422);
    }
}
