<?php

namespace App\Exceptions\Commercial;

use App\Models\CashRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A pessoa já tem um caixa aberto — critério de aceite do doc 01 ("não consegue abrir dois
 * caixas simultâneos para si").
 *
 * A resposta devolve o caixa existente para que o app possa simplesmente NAVEGAR até ele em
 * vez de mostrar um erro: quem clica em "abrir caixa" com um já aberto quer operar naquele.
 */
final class CashRegisterAlreadyOpenException extends RuntimeException
{
    public function __construct(private readonly CashRegister $existing)
    {
        parent::__construct(sprintf(
            'Você já tem um caixa aberto desde %s. Feche-o antes de abrir outro.',
            $existing->opened_at->format('d/m/Y H:i')
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
            'code' => 'cash_register_already_open',
            'cash_register' => [
                'id' => $this->existing->id,
                'opened_at' => $this->existing->opened_at->toIso8601String(),
            ],
        ], 422);
    }
}
