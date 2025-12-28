<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ConsultationRecording;
use App\Models\VideoConsultation;
use App\Services\Video\RecordingService;
use App\Services\Video\VideoCallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoConsultationController extends Controller
{
    public function __construct(
        private readonly VideoCallService $videoCallService,
        private readonly RecordingService $recordingService
    ) {}

    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
            'recording_enabled' => 'boolean',
        ]);

        $appointment = Appointment::findOrFail($validated['appointment_id']);

        // Authorization check
        $user = $request->user();
        if ($appointment->user_id !== $user->id && $appointment->professional_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $consultation = $this->videoCallService->createConsultation(
            $appointment,
            $validated['recording_enabled'] ?? false
        );

        return response()->json([
            'message' => 'Video consultation created',
            'data' => $consultation,
        ], 201);
    }

    public function join(Request $request, int $id): JsonResponse
    {
        $consultation = VideoConsultation::with('appointment')->findOrFail($id);
        $user = $request->user();

        // Authorization check
        if ($consultation->appointment->user_id !== $user->id && 
            $consultation->appointment->professional_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $isOwner = $consultation->appointment->professional_id === $user->id;
        $token = $this->videoCallService->generateToken($consultation, $user->id, $isOwner);

        // Update status to waiting if professional joins first
        if ($isOwner && $consultation->status === 'scheduled') {
            $consultation->update(['status' => 'waiting']);
        }

        return response()->json([
            'token' => $token,
            'room_url' => $this->videoCallService->getRoomUrl($consultation->room_id),
            'room_id' => $consultation->room_id,
            'is_owner' => $isOwner,
            'consultation' => $consultation,
        ]);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $consultation = VideoConsultation::findOrFail($id);

        // Only professional can start
        if ($consultation->appointment->professional_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $consultation->start();

        return response()->json([
            'message' => 'Consultation started',
            'data' => $consultation,
        ]);
    }

    public function end(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:5000',
        ]);

        $consultation = VideoConsultation::findOrFail($id);

        // Only professional can end
        if ($consultation->appointment->professional_id !== $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $consultation->end();

        if (isset($validated['notes'])) {
            $consultation->update(['notes' => $validated['notes']]);
        }

        // Fetch recordings if enabled
        if ($consultation->recording_enabled) {
            $this->recordingService->fetchRecordings($consultation);
        }

        return response()->json([
            'message' => 'Consultation ended',
            'data' => $consultation->load('recordings'),
        ]);
    }

    public function grantRecordingConsent(Request $request, int $recordingId): JsonResponse
    {
        $recording = ConsultationRecording::with('videoConsultation.appointment')->findOrFail($recordingId);

        // Both parties must consent
        $user = $request->user();
        $appointment = $recording->videoConsultation->appointment;

        if ($appointment->user_id !== $user->id && $appointment->professional_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $this->recordingService->grantConsent($recording);

        return response()->json(['message' => 'Consent granted']);
    }

    public function revokeRecordingConsent(Request $request, int $recordingId): JsonResponse
    {
        $recording = ConsultationRecording::with('videoConsultation.appointment')->findOrFail($recordingId);

        $user = $request->user();
        $appointment = $recording->videoConsultation->appointment;

        if ($appointment->user_id !== $user->id && $appointment->professional_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $this->recordingService->revokeConsent($recording);

        return response()->json(['message' => 'Consent revoked and recording deleted']);
    }
}

