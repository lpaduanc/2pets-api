<?php

namespace App\Services\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

final class NotificationService
{
    public function __construct(
        private readonly WhatsAppService $whatsAppService,
        private readonly SmsService $smsService
    ) {}

    /**
     * Todo `NotificationType` hoje é comunicação operacional (lembrete de consulta, vacina,
     * pagamento, mensagem...) — nenhum é campanha de marketing. Por isso o portão aqui é
     * incondicional: conta desativada não recebe nenhum push/e-mail/SMS/WhatsApp/notificação
     * in-app operacional (decisão do dono do produto, 2026-09-13 — ver
     * `AccountDeactivationService`). Quando existir um `NotificationType` de campanha de
     * reativação, ele não deve passar por `sendNotification()` — usa `$user->notify()`
     * diretamente com uma Notification que implemente `BypassesDeactivationGate`.
     */
    /**
     * `$actionUrl` e o destino do toque (in-app e push). Opcional porque nem
     * toda notificacao tem tela propria; quando tem, sem isto ela chega sem
     * saida — ver {@see \App\Notifications\InAppNotification}.
     *
     * `$mail` e o e-mail DE VERDADE deste tipo (uma `Notification` com `toMail()`),
     * entregue so quando o canal EMAIL estiver ligado para o tipo — mesma regra de
     * preferencia e de conta desativada dos outros canais. Sem ele, o canal EMAIL
     * continua no stub (`sendEmail()`): ligar e-mail real para todos os tipos de uma
     * vez faria lembrete, pagamento e mensagem comecarem a sair sem template revisado.
     */
    public function sendNotification(
        User $user,
        NotificationType $type,
        string $title,
        string $body,
        array $data = [],
        ?string $actionUrl = null,
        ?Notification $mail = null,
    ): void {
        if ($user->isDeactivated()) {
            Log::info('Operational notification suppressed: recipient account is deactivated', [
                'user_id' => $user->id,
                'type' => $type->value,
            ]);

            return;
        }

        $channels = $this->getEnabledChannels($user, $type);

        // O push tambem precisa do destino: `usePushNotifications.js` navega por
        // `notification.data.action_url` quando o usuario toca na bandeja.
        $payload = $actionUrl === null ? $data : [...$data, 'action_url' => $actionUrl];

        foreach ($channels as $channel) {
            try {
                $this->sendToChannel($channel, $user, $title, $body, $payload, $mail);
            } catch (\Exception $e) {
                Log::error("Failed to send notification via {$channel->value}", [
                    'user_id' => $user->id,
                    'type' => $type->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->createInAppNotification($user, $type, $title, $body, $data, $actionUrl);
    }

    /**
     * As linhas de `notification_preferences` sao OVERRIDES por canal sobre o
     * default do tipo — nao a lista completa de canais.
     *
     * O filtro `where('enabled', true)` que existia aqui fazia um opt-out
     * desaparecer: desligar o push de `lost_pet_alert_nearby` grava a linha com
     * `enabled = false`, a consulta voltava vazia, o metodo caia no
     * `getDefaultChannels()` e o push saia assim mesmo. Pior no outro sentido:
     * desligar SO o e-mail de um tipo cujo default e `[PUSH, EMAIL]` deixava a
     * consulta com uma linha (o push) e silenciosamente reduzia o conjunto ao
     * que estivesse gravado, perdendo canais default nunca tocados pelo usuario.
     *
     * @return list<NotificationChannel>
     */
    private function getEnabledChannels(User $user, NotificationType $type): array
    {
        $overrides = NotificationPreference::where('user_id', $user->id)
            ->where('notification_type', $type->value)
            ->get()
            ->keyBy('channel');

        if ($overrides->isEmpty()) {
            return $this->getDefaultChannels($type);
        }

        $defaults = $this->getDefaultChannels($type);

        return array_values(array_filter(
            NotificationChannel::cases(),
            fn (NotificationChannel $channel) => $overrides->has($channel->value)
                ? (bool) $overrides[$channel->value]->enabled
                : in_array($channel, $defaults, true)
        ));
    }

    private function getDefaultChannels(NotificationType $type): array
    {
        return match ($type) {
            NotificationType::APPOINTMENT_REMINDER_24H,
            NotificationType::APPOINTMENT_REMINDER_2H => [
                NotificationChannel::PUSH,
                NotificationChannel::EMAIL,
            ],
            NotificationType::APPOINTMENT_REQUESTED,
            NotificationType::APPOINTMENT_CONFIRMED,
            NotificationType::APPOINTMENT_REJECTED,
            NotificationType::APPOINTMENT_CANCELLED,
            NotificationType::APPOINTMENT_RESCHEDULED => [
                NotificationChannel::PUSH,
                NotificationChannel::EMAIL,
            ],
            NotificationType::PAYMENT_RECEIVED,
            NotificationType::PAYMENT_FAILED,
            NotificationType::QUOTE_RECEIVED,
            NotificationType::QUOTE_APPROVED,
            NotificationType::QUOTE_REJECTED => [
                NotificationChannel::EMAIL,
                NotificationChannel::PUSH,
            ],
            NotificationType::NEW_MESSAGE,
            NotificationType::LOST_PET_ALERT_NEARBY => [
                NotificationChannel::PUSH,
            ],
            default => [NotificationChannel::EMAIL],
        };
    }

    private function sendToChannel(
        NotificationChannel $channel,
        User $user,
        string $title,
        string $body,
        array $data,
        ?Notification $mail = null,
    ): void {
        match ($channel) {
            // Fase 4: push deixou de ser disparado aqui. `createInAppNotification()`
            // (fim deste método) grava um `InAppNotification`, cujo `via()` já inclui o
            // canal `fcm` (`App\Notifications\Channels\FcmChannel`) — mandar por aqui
            // TAMBÉM duplicaria o push no aparelho do usuário. A checagem de preferência
            // de push continua valendo: é feita de novo, com a mesma tabela
            // `notification_preferences`, dentro do `FcmChannel`/`PushPreferenceGate`.
            NotificationChannel::PUSH => null,
            NotificationChannel::WHATSAPP => $this->whatsAppService->send($user, $body),
            NotificationChannel::SMS => $this->smsService->send($user, $body),
            NotificationChannel::EMAIL => $mail !== null && filled($user->email)
                ? $user->notify($mail)
                : $this->sendEmail($user, $title, $body, $data),
        };
    }

    private function sendEmail(User $user, string $title, string $body, array $data): void
    {
        // Using Laravel's built-in Mail system
        // Will be implemented with Mailable classes
        Log::info("Email sent to {$user->email}", ['title' => $title]);
    }

    private function createInAppNotification(
        User $user,
        NotificationType $type,
        string $title,
        string $body,
        array $data,
        ?string $actionUrl
    ): void {
        $user->notify(new \App\Notifications\InAppNotification(
            $type,
            $title,
            $body,
            $data,
            $actionUrl
        ));
    }
}
