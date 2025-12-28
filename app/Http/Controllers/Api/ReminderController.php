<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reminder;
use App\Models\ReminderPreference;
use App\Services\Reminder\HealthReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function __construct(
        private readonly HealthReminderService $reminderService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $reminders = Reminder::where('user_id', $request->user()->id)
            ->with('pet')
            ->orderBy('reminder_date')
            ->paginate(20);

        return response()->json($reminders);
    }

    public function pending(Request $request): JsonResponse
    {
        $reminders = $this->reminderService->getPendingReminders($request->user()->id);

        return response()->json(['data' => $reminders]);
    }

    public function snooze(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:30',
        ]);

        $reminder = Reminder::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $reminder->snooze($validated['days']);

        return response()->json(['message' => 'Reminder snoozed']);
    }

    public function dismiss(Request $request, int $id): JsonResponse
    {
        $reminder = Reminder::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $reminder->dismiss();

        return response()->json(['message' => 'Reminder dismissed']);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $reminder = Reminder::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $reminder->complete();

        return response()->json(['message' => 'Reminder completed']);
    }

    public function getPreferences(Request $request): JsonResponse
    {
        $preferences = ReminderPreference::where('user_id', $request->user()->id)->get();

        return response()->json(['data' => $preferences]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => 'required|array',
            'preferences.*.type' => 'required|string|in:vaccination,medication,checkup,deworming',
            'preferences.*.days_before' => 'required|integer|min:1|max:90',
            'preferences.*.email_enabled' => 'required|boolean',
            'preferences.*.push_enabled' => 'required|boolean',
            'preferences.*.whatsapp_enabled' => 'required|boolean',
        ]);

        foreach ($validated['preferences'] as $pref) {
            ReminderPreference::updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'type' => $pref['type'],
                ],
                [
                    'days_before' => $pref['days_before'],
                    'email_enabled' => $pref['email_enabled'],
                    'push_enabled' => $pref['push_enabled'],
                    'whatsapp_enabled' => $pref['whatsapp_enabled'],
                ]
            );
        }

        return response()->json(['message' => 'Preferences updated']);
    }
}

