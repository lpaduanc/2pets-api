<?php

namespace App\Services\Notification;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class PushNotificationService
{
    private ?string $fcmServerKey;
    private string $fcmUrl = 'https://fcm.googleapis.com/fcm/send';

    public function __construct()
    {
        $this->fcmServerKey = config('services.fcm.server_key');
    }

    public function send(User $user, string $title, string $body, array $data = []): void
    {
        if (!$this->fcmServerKey) {
            Log::warning('FCM server key not configured');
            return;
        }

        $subscriptions = PushSubscription::where('user_id', $user->id)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            $this->sendToDevice($subscription->device_token, $title, $body, $data);
        }
    }

    private function sendToDevice(string $deviceToken, string $title, string $body, array $data): void
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "key={$this->fcmServerKey}",
                'Content-Type' => 'application/json',
            ])->post($this->fcmUrl, [
                'to' => $deviceToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'sound' => 'default',
                    'badge' => 1,
                ],
                'data' => $data,
                'priority' => 'high',
            ]);

            if (!$response->successful()) {
                Log::error('FCM push notification failed', [
                    'device_token' => $deviceToken,
                    'response' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('FCM push notification error', [
                'device_token' => $deviceToken,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function registerDevice(User $user, string $deviceToken, ?string $deviceType = null): void
    {
        PushSubscription::updateOrCreate(
            [
                'user_id' => $user->id,
                'device_token' => $deviceToken,
            ],
            [
                'device_type' => $deviceType,
                'last_used_at' => now(),
            ]
        );
    }

    public function unregisterDevice(User $user, string $deviceToken): void
    {
        PushSubscription::where('user_id', $user->id)
            ->where('device_token', $deviceToken)
            ->delete();
    }
}

