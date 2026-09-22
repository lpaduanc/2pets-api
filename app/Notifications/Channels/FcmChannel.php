<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Notification\PushNotificationService;
use App\Services\Notification\PushPreferenceGate;
use Illuminate\Notifications\Notification;

/**
 * Canal de notificação do Laravel para push via FCM (Fase 4 do fluxo de agendamento) —
 * plugado em `PushNotificationService` (que já resolve credencial ausente, payload
 * Android/iOS e token morto). Usado retornando o FQCN direto em `via()`
 * (`['database', FcmChannel::class]`), sem precisar de `Notification::extend()`: o
 * `ChannelManager` do Laravel resolve qualquer classe existente pelo container quando o
 * nome do canal não é um driver nativo.
 *
 * Só entrega quando a Notification implementa `toFcm($notifiable): array` — formato:
 * `['type' => string, 'title' => string, 'body' => string, 'data' => array<string, mixed>]`.
 * Sem esse método, a notificação simplesmente não usa este canal (nada quebra) — é o
 * "opt-in explícito": quem quer push controla título/corpo/deep link, em vez de um
 * fallback genérico tentando adivinhar. Ver `DispatchFallbackPushNotification` para a
 * cobertura das notificações que NÃO implementam `toFcm()`.
 */
final class FcmChannel
{
    public function __construct(
        private readonly PushNotificationService $pushService,
        private readonly PushPreferenceGate $preferenceGate,
    ) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toFcm')) {
            return;
        }

        $payload = $notification->toFcm($notifiable);

        if (! $this->preferenceGate->allows($notifiable, $payload['type'])) {
            return;
        }

        $this->pushService->send($notifiable, $payload['title'], $payload['body'], $payload['data'] ?? []);
    }
}
