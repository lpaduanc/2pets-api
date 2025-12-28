<?php

namespace App\Services\Notification;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class WhatsAppService
{
    private ?string $apiUrl;
    private ?string $apiKey;
    private ?string $phoneNumberId;

    public function __construct()
    {
        $this->apiUrl = config('services.whatsapp.api_url');
        $this->apiKey = config('services.whatsapp.api_key');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id');
    }

    public function send(User $user, string $message): void
    {
        if (!$this->apiKey || !$this->phoneNumberId) {
            Log::warning('WhatsApp API not configured');
            return;
        }

        if (empty($user->phone)) {
            Log::warning('User has no phone number', ['user_id' => $user->id]);
            return;
        }

        $phone = $this->formatPhoneNumber($user->phone);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$this->apiUrl}/{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'text',
                'text' => [
                    'body' => $message,
                ],
            ]);

            if (!$response->successful()) {
                Log::error('WhatsApp message failed', [
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'response' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('WhatsApp message error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatPhoneNumber(string $phone): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Add country code if not present (Brazil: 55)
        if (strlen($phone) === 11 && !str_starts_with($phone, '55')) {
            $phone = '55' . $phone;
        }

        return $phone;
    }
}

