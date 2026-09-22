<?php

namespace App\Listeners\Notifications;

use App\Models\User;
use App\Services\Notification\PushNotificationService;
use App\Services\Notification\PushPreferenceGate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;

/**
 * "Qualquer tipo de notificação" precisa virar push (Fase 4 do fluxo de agendamento) —
 * inclusive as ~19 classes de `App\Notifications` que já existiam antes desta fase
 * (`WelcomeNotification`, `PaymentConfirmedNotification`, `CrmvApprovedNotification`...) e
 * não declaram o canal `fcm`. Editar `via()` uma a uma era o caminho óbvio e o que este
 * listener evita: ele ouve `NotificationSent` para o canal `database` — que TODAS elas já
 * usam — e reaproveita o MESMO `toArray()`/`toDatabase()` que cada uma já escreve para a
 * lista de notificações in-app, sem precisar tocar em nenhuma delas.
 *
 * Convenção que isto assume (já estabelecida no projeto, não inventada aqui — ver
 * `App\Notifications\InAppNotification`): o array tem `title`, `message` (corpo) e,
 * opcionalmente, `action_url` (deep link) e `type` (chave de preferência). Notification
 * sem `title`/`message` nesse formato é ignorada — melhor não empurrar push nenhum do que
 * um com título vazio.
 *
 * Notification que já implementa `toFcm()` (opt-in explícito, canal `FcmChannel` formal em
 * `via()`) é ignorada aqui de propósito — sem essa saída, `InAppNotification` receberia
 * DOIS pushes para o mesmo evento.
 */
final class DispatchFallbackPushNotification implements ShouldQueue
{
    public function __construct(
        private readonly PushNotificationService $pushService,
        private readonly PushPreferenceGate $preferenceGate,
    ) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || method_exists($event->notification, 'toFcm')) {
            return;
        }

        if (! $event->notifiable instanceof User) {
            return;
        }

        $payload = $this->extractPayload($event->notification, $event->notifiable);

        if ($payload === null || ! $this->preferenceGate->allows($event->notifiable, $payload['type'])) {
            return;
        }

        $this->pushService->send($event->notifiable, $payload['title'], $payload['body'], $payload['data']);
    }

    /**
     * @return array{type: string, title: string, body: string, data: array<string, mixed>}|null
     */
    private function extractPayload(Notification $notification, User $notifiable): ?array
    {
        $array = $this->databaseArrayFor($notification, $notifiable);

        if ($array === null || ! isset($array['title'], $array['message'])) {
            return null;
        }

        $type = (string) ($array['type'] ?? $notification::class);

        return [
            'type' => $type,
            'title' => (string) $array['title'],
            'body' => (string) $array['message'],
            'data' => [
                'type' => $type,
                'action_url' => $array['action_url'] ?? null,
            ],
        ];
    }

    /**
     * `toDatabase()` é preferido quando existe (é o que o `DatabaseChannel` do Laravel
     * chama de verdade); a maioria das 19 classes só implementa `toArray()`, que o
     * `DatabaseChannel` usa como fallback quando `toDatabase()` não existe — mesma ordem
     * de resolução, replicada aqui.
     *
     * @return array<string, mixed>|null
     */
    private function databaseArrayFor(Notification $notification, User $notifiable): ?array
    {
        if (method_exists($notification, 'toDatabase')) {
            return $notification->toDatabase($notifiable);
        }

        if (method_exists($notification, 'toArray')) {
            return $notification->toArray($notifiable);
        }

        return null;
    }
}
