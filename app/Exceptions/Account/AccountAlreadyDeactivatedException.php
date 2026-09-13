<?php

namespace App\Exceptions\Account;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class AccountAlreadyDeactivatedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Esta conta já está desativada.');
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
