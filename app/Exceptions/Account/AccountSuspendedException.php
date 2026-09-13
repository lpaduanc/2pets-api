<?php

namespace App\Exceptions\Account;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Uma conta suspensa (punitiva, decidida pelo admin) não pode se autorreativar pelo fluxo de
 * desativação voluntária — só o admin levanta a suspensão (`AdminController::activateUser`).
 * Sem este bloqueio, o endpoint público de reativação viraria um jeito de burlar suspensão
 * bastando saber a senha, que a pessoa suspensa continua sabendo.
 */
final class AccountSuspendedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sua conta foi suspensa. Entre em contato com o suporte para mais informações.');
    }

    /** Resultado de negócio esperado, não incidente: não polui o log com stack trace. */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'account_suspended' => true], 403);
    }
}
