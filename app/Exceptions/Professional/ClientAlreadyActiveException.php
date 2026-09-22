<?php

namespace App\Exceptions\Professional;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * `POST professional/clients/{client}/invite` só faz sentido para conta ainda NÃO
 * reivindicada (`User::isUnclaimed()`) — contrato
 * `docs/gap-simplesvet/specs/19-portal-do-cliente-spec.md`. Cliente com conta ativa já tem
 * acesso próprio; reenviar um "convite" para ele não é uma operação válida.
 */
final class ClientAlreadyActiveException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Este cliente já tem conta ativa no 2pets — não há convite para reenviar.');
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
