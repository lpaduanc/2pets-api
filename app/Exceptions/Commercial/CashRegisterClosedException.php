<?php

namespace App\Exceptions\Commercial;

use App\Models\CashRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Tentativa de movimentar um caixa que não está aberto — critério de aceite do doc 01
 * ("Caixa `closed` rejeita novo movimento com 422 e mensagem clara").
 *
 * A mensagem diz o estado ATUAL e o que fazer, porque o operador que bate nisto normalmente
 * abriu o caixa ontem e esqueceu: "abra um caixa novo" é a ação, não "caixa inválido".
 */
final class CashRegisterClosedException extends RuntimeException
{
    public function __construct(private readonly CashRegister $cashRegister)
    {
        parent::__construct(sprintf(
            'Este caixa está %s e não aceita novos movimentos. Abra um novo caixa para continuar vendendo.',
            mb_strtolower($cashRegister->status->label())
        ));
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
            'code' => 'cash_register_closed',
            'cash_register' => [
                'id' => $this->cashRegister->id,
                'status' => $this->cashRegister->status->value,
                'status_label' => $this->cashRegister->status->label(),
            ],
        ], 422);
    }
}
