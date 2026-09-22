<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InAppNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly NotificationType $type,
        private readonly string $title,
        private readonly string $body,
        private readonly array $data = [],
        /**
         * Deep link do app, no TOPO do payload — e onde `NotificationResource`
         * o le (`$payload['action_url']`) e de onde `NotificationsPage.vue` e
         * `usePushNotifications.js` navegam ao toque.
         *
         * Antes disto, toda notificacao emitida por `NotificationService` (pet
         * perdido, lembrete de consulta, pagamento...) chegava sem destino:
         * dava para ler na lista, mas tocar nela nao levava a lugar nenhum. As
         * `Notification` escritas a mao ja gravavam `action_url`; so quem
         * passava por `sendNotification()` nao tinha como.
         */
        private readonly ?string $actionUrl = null
    ) {}

    /**
     * Fase 4 do fluxo de agendamento: `fcm` entrega push de verdade (Android/iOS, com o
     * app fechado) via `FcmChannel` — sem credencial FCM configurada,
     * `PushNotificationService::isConfigured()` pula com log e o canal `database` (a
     * notificação in-app) continua funcionando normalmente.
     */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            'action_url' => $this->actionUrl,
            // Every other Notification class in the app writes the payload under `message`
            // (see `NotificationResource`) — this used to write `body`, which the Resource
            // never read, so `SendAppointmentNotification`/`SendReviewNotification` reached
            // the API with an empty message.
            'message' => $this->body,
            'data' => $this->data,
        ];
    }

    /**
     * Payload do push (Fase 4) — `data.action_url` é a MESMA chave que
     * `usePushNotifications.js` já lê no app (`notification.data.action_url`) para navegar
     * ao toque; não é uma convenção nova desta fase, é a que o frontend já tinha.
     *
     * @return array{type: string, title: string, body: string, data: array<string, mixed>}
     */
    public function toFcm(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            'body' => $this->body,
            'data' => [
                ...$this->data,
                'type' => $this->type->value,
                'action_url' => $this->actionUrl,
            ],
        ];
    }
}
