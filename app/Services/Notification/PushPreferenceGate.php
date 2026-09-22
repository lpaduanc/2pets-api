<?php

namespace App\Services\Notification;

use App\Enums\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\User;

/**
 * "A pessoa desligou push para este tipo de notificação?" — usado tanto por
 * `App\Notifications\Channels\FcmChannel` (canal formal, `Notification::via()`) quanto pelo
 * listener de fallback (`DispatchFallbackPushNotification`), para as duas rotas de push do
 * projeto lerem a MESMA regra de `notification_preferences` (Fase 4 do fluxo de
 * agendamento).
 *
 * Sem preferência gravada, o padrão é PERMITIR — a ausência de configuração nunca deve
 * silenciar uma notificação nova; é o usuário quem desliga, explicitamente, em
 * `NotificationController::updatePreferences()`.
 */
final class PushPreferenceGate
{
    public function allows(User $user, string $notificationType): bool
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('channel', NotificationChannel::PUSH->value)
            ->where('notification_type', $notificationType)
            ->first();

        return $preference === null || $preference->enabled;
    }
}
