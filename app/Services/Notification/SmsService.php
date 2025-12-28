<?php

namespace App\Services\Notification;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class SmsService
{
    private ?string $apiUrl;
    private ?string $apiKey;
    private ?string $fromNumber;

    public function __construct()
    {
        // Using Zenvia as default SMS provider (popular in Brazil)
        $this->apiUrl = config('services.sms.api_url', 'https://api.zenvia.com/v2');
        $this->apiKey = config('services.sms.api_key');
        $this->fromNumber = config('services.sms.from_number', '2Pets');
    }

    public function send(User $user, string $message): void
    {
        if (!$this->apiKey) {
            Log::warning('SMS API not configured');
            return;
        }

        if (empty($user->phone)) {
            Log::warning('User has no phone number', ['user_id' => $user->id]);
            return;
        }

        $phone = $this->formatPhoneNumber($user->phone);

        try {
            $response = Http::withHeaders([
                'X-API-TOKEN' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->apiUrl}/channels/sms/messages", [
                'from' => $this->fromNumber,
                'to' => $phone,
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $message,
                    ],
                ],
            ]);

            if (!$response->successful()) {
                Log::error('SMS failed', [
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'response' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('SMS error', [
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

