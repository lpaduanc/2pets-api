<?php

namespace App\Exceptions\PetAccess;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * O vínculo não está no estado exigido pela operação que o tutor pediu.
 *
 * Não é 403 (o tutor É dono do pet e pode operar sobre o vínculo) nem 404 (o registro existe):
 * é 422, o pedido não se aplica ao estado atual.
 */
final class InvalidVetAccessTransitionException extends RuntimeException
{
    public static function notPending(): self
    {
        return new self('Esta solicitação não está mais pendente.');
    }

    public static function notAccepted(): self
    {
        return new self('Este acesso não está ativo.');
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
