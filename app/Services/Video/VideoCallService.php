<?php

namespace App\Services\Video;

use App\Models\Appointment;
use App\Models\VideoConsultation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class VideoCallService
{
    private ?string $apiKey;
    private string $baseUrl = 'https://api.daily.co/v1';

    public function __construct()
    {
        $this->apiKey = config('services.daily.api_key');
    }

    public function createConsultation(
        Appointment $appointment,
        bool $recordingEnabled = false
    ): VideoConsultation {
        $roomId = $this->createRoom($appointment, $recordingEnabled);

        return VideoConsultation::create([
            'appointment_id' => $appointment->id,
            'room_id' => $roomId,
            'provider' => 'daily',
            'status' => 'scheduled',
            'recording_enabled' => $recordingEnabled,
        ]);
    }

    public function generateToken(
        VideoConsultation $consultation,
        int $userId,
        bool $isOwner = false
    ): string {
        if (!$this->apiKey) {
            return $this->generateMockToken($consultation->room_id, $userId);
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/meeting-tokens", [
            'properties' => [
                'room_name' => $consultation->room_id,
                'user_id' => (string) $userId,
                'is_owner' => $isOwner,
                'enable_recording' => $consultation->recording_enabled ? 'cloud' : 'off',
                'start_cloud_recording' => $consultation->recording_enabled,
                'exp' => time() + 3600, // 1 hour
            ],
        ]);

        if ($response->failed()) {
            return $this->generateMockToken($consultation->room_id, $userId);
        }

        return $response->json('token');
    }

    public function getRoomUrl(string $roomId): string
    {
        return "https://2pets.daily.co/{$roomId}";
    }

    public function deleteRoom(string $roomId): void
    {
        if (!$this->apiKey) {
            return;
        }

        Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
        ])->delete("{$this->baseUrl}/rooms/{$roomId}");
    }

    private function createRoom(Appointment $appointment, bool $recordingEnabled): string
    {
        $roomName = "consultation-{$appointment->id}-" . Str::random(8);

        if (!$this->apiKey) {
            return $roomName;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/rooms", [
            'name' => $roomName,
            'privacy' => 'private',
            'properties' => [
                'enable_recording' => $recordingEnabled ? 'cloud' : 'off',
                'enable_screen_share' => true,
                'enable_chat' => true,
                'start_video_off' => false,
                'start_audio_off' => false,
                'exp' => time() + 86400, // 24 hours
            ],
        ]);

        if ($response->failed()) {
            return $roomName;
        }

        return $response->json('name');
    }

    private function generateMockToken(string $roomId, int $userId): string
    {
        return base64_encode(json_encode([
            'room_id' => $roomId,
            'user_id' => $userId,
            'exp' => time() + 3600,
        ]));
    }
}

