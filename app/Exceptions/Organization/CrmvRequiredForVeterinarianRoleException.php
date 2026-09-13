<?php

namespace App\Exceptions\Organization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Regra regulatória não-negociável: ninguém aceita convite de `veterinarian`, nem é
 * promovido a `veterinarian` num vínculo existente, sem `professionals.crmv` preenchido —
 * Lei 5.517/1968 art. 1º; Res. CFMV 1.318/2020 e 1.321/2020
 * (ver `docs/rbac-clinica-autoria-e-staff.md` §3 e §7).
 */
final class CrmvRequiredForVeterinarianRoleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Para assumir o cargo de veterinário é necessário ter um CRMV cadastrado no perfil.');
    }

    /** Resultado de negócio esperado, não incidente: não polui o log com stack trace. */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['role' => [$this->getMessage()]],
        ], 422);
    }
}
