<?php

namespace App\Exceptions\Organization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Convite existe (token válido, 404 não se aplica) mas não pode mais ser aceito — expirado ou
 * revogado. Aceite de convite já aceito não passa por aqui: é idempotente
 * (`OrganizationInvitationService::accept`).
 */
final class InvitationNotAcceptableException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function expired(): self
    {
        return new self('Este convite expirou.');
    }

    public static function revoked(): self
    {
        return new self('Este convite foi revogado.');
    }

    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
