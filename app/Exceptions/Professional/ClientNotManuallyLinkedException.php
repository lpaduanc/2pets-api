<?php

namespace App\Exceptions\Professional;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * `DELETE /professional/clients/{id}` só desfaz um vínculo MANUAL
 * (`ProfessionalClient`, ver `ClientProvisioningService`). Quando o cliente é apenas
 * derivado de agendamento, fatura ou grant de acesso ao pet, não existe uma linha própria
 * para remover — apagar a conta do usuário para "sumir" com ele da lista era o bug real que
 * existia antes desta exceção.
 */
final class ClientNotManuallyLinkedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Este cliente não tem vínculo manual com você — ele aparece na lista por '.
            'agendamento, fatura ou acesso ao pet, e esse histórico não pode ser removido por aqui.'
        );
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
