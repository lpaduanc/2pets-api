<?php

namespace App\Services\Video;

use App\Models\ConsultationRecording;
use App\Models\VideoConsultation;
use Illuminate\Support\Facades\Http;

final class RecordingService
{
    private ?string $apiKey;
    private string $baseUrl = 'https://api.daily.co/v1';

    public function __construct()
    {
        $this->apiKey = config('services.daily.api_key');
    }

    public function fetchRecordings(VideoConsultation $consultation): void
    {
        if (!$this->apiKey || !$consultation->recording_enabled) {
            return;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
        ])->get("{$this->baseUrl}/recordings", [
            'room_name' => $consultation->room_id,
        ]);

        if ($response->failed()) {
            return;
        }

        $recordings = $response->json('data', []);

        foreach ($recordings as $recording) {
            $this->storeRecording($consultation, $recording);
        }
    }

    public function grantConsent(ConsultationRecording $recording): void
    {
        $recording->update(['consent_given' => true]);
    }

    public function revokeConsent(ConsultationRecording $recording): void
    {
        $recording->update(['consent_given' => false]);

        // Optionally delete recording from provider
        if ($this->apiKey && $recording->recording_id) {
            $this->deleteRecording($recording->recording_id);
        }
    }

    private function storeRecording(VideoConsultation $consultation, array $recordingData): void
    {
        ConsultationRecording::updateOrCreate(
            [
                'video_consultation_id' => $consultation->id,
                'recording_id' => $recordingData['id'],
            ],
            [
                'recording_url' => $recordingData['download_link'] ?? $recordingData['share_link'] ?? '',
                'duration_seconds' => $recordingData['duration'] ?? 0,
                'status' => $recordingData['status'] === 'finished' ? 'ready' : 'processing',
                'consent_given' => false,
            ]
        );
    }

    private function deleteRecording(string $recordingId): void
    {
        if (!$this->apiKey) {
            return;
        }

        Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
        ])->delete("{$this->baseUrl}/recordings/{$recordingId}");
    }
}

