<?php

namespace App\Exceptions\Professional;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * `POST professional/clients/{client}/invite` precisa de um e-mail para entregar o link de
 * continuação de cadastro (`RegistrationContinuationTokenService`) — sem ele não há canal
 * para o convite chegar, mesma trava de `NewPatientNotificationService::notify()`.
 */
final class ClientWithoutEmailException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Este cliente não tem e-mail cadastrado — informe um e-mail antes de enviar o convite.');
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
