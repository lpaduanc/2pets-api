<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Descadastro em um clique (contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §7). Rota `signed`, sem
 * `auth:sanctum`: o link vem de um e-mail, não de uma sessão logada — mesmo raciocínio de
 * `DocumentFileController` (link direto não carrega bearer token).
 */
class MarketingUnsubscribeController extends Controller
{
    public function unsubscribe(Request $request, User $user): JsonResponse
    {
        $user->update(['marketing_consent' => false]);

        ConsentLog::create([
            'user_id' => $user->id,
            'consent_key' => 'marketing_email',
            'granted' => false,
            'source' => 'unsubscribe_link',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'occurred_at' => now(),
        ]);

        return response()->json([
            'message' => 'Voce nao vai mais receber e-mails de oferta do 2pets.',
        ]);
    }
}
