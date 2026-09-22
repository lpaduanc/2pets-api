<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Descadastro em um clique (contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §7, generalizado pelo
 * item 17 do backlog gap-simplesvet para os 3 canais). Rota `signed`, sem `auth:sanctum`: o
 * link vem de um e-mail/SMS/WhatsApp, não de uma sessão logada — mesmo raciocínio de
 * `DocumentFileController` (link direto não carrega bearer token).
 *
 * `?channel=` é OPCIONAL e default `email` — os dois chamadores existentes
 * (`NewPatientNotificationService`, `ClientInviteService`) geram a URL sem esse parâmetro, e
 * continuam descadastrando só e-mail, sem nenhuma mudança de comportamento.
 */
class MarketingUnsubscribeController extends Controller
{
    private const CHANNEL_COLUMN = [
        'email' => 'marketing_consent',
        'sms' => 'consent_sms_marketing',
        'whatsapp' => 'consent_whatsapp_marketing',
    ];

    public function unsubscribe(Request $request, User $user): JsonResponse
    {
        $channel = $this->resolveChannel($request);

        $user->update([self::CHANNEL_COLUMN[$channel] => false]);

        ConsentLog::create([
            'user_id' => $user->id,
            'consent_key' => "marketing_{$channel}",
            'granted' => false,
            'source' => 'unsubscribe_link',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'occurred_at' => now(),
        ]);

        return response()->json(['message' => $this->confirmationMessageFor($channel)]);
    }

    private function resolveChannel(Request $request): string
    {
        $channel = (string) $request->query('channel', 'email');

        return array_key_exists($channel, self::CHANNEL_COLUMN) ? $channel : 'email';
    }

    private function confirmationMessageFor(string $channel): string
    {
        return match ($channel) {
            'sms' => 'Voce nao vai mais receber SMS promocional do 2pets.',
            'whatsapp' => 'Voce nao vai mais receber mensagem promocional do 2pets no WhatsApp.',
            default => 'Voce nao vai mais receber e-mails de oferta do 2pets.',
        };
    }
}
