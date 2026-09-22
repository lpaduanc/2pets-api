<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Commercial\ClientAccountEntryResource;
use App\Services\Commercial\ClientAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Extrato do PRÓPRIO tutor numa clínica — contrato
 * docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md ("vantagem sobre o SimplesVet, que
 * só mostra do lado da clínica"). Sempre o saldo de QUEM ESTÁ LOGADO, nunca um `client_id`
 * vindo do app — não tem como um tutor ver o extrato de outro por aqui.
 */
class ClientAccountStatementController extends Controller
{
    public function __construct(private readonly ClientAccountService $accounts) {}

    public function show(Request $request, int $organizationId): JsonResponse
    {
        $ownership = ['organization_id' => $organizationId, 'professional_id' => $request->user()->id];

        return response()->json([
            'balance' => $this->accounts->balanceFor($request->user(), $ownership),
            'data' => ClientAccountEntryResource::collection(
                $this->accounts->statementFor($request->user(), $ownership)->load('recordedBy')
            ),
        ]);
    }
}
