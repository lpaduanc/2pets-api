<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Services\Notification\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly PushNotificationService $pushService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($notifications);
    }

    public function unread(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->unreadNotifications()
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $notifications,
            'count' => $notifications->count(),
        ]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->findOrFail($id);

        $notification->markAsRead();

        return response()->json(['message' => 'Notification marked as read']);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read']);
    }

    public function getPreferences(Request $request): JsonResponse
    {
        $preferences = NotificationPreference::where('user_id', $request->user()->id)
            ->get()
            ->groupBy('notification_type');

        $allTypes = collect(NotificationType::cases())->mapWithKeys(function ($type) use ($preferences) {
            $typePrefs = $preferences->get($type->value, collect());

            return [
                $type->value => [
                    'label' => $type->label(),
                    'channels' => collect(NotificationChannel::cases())->mapWithKeys(function ($channel) use ($typePrefs) {
                        $pref = $typePrefs->firstWhere('channel', $channel->value);

                        return [
                            $channel->value => [
                                'label' => $channel->label(),
                                'enabled' => $pref ? $pref->enabled : true,
                            ],
                        ];
                    }),
                ],
            ];
        });

        return response()->json(['data' => $allTypes]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => 'required|array',
            'preferences.*.notification_type' => 'required|string',
            'preferences.*.channel' => 'required|string',
            'preferences.*.enabled' => 'required|boolean',
        ]);

        foreach ($validated['preferences'] as $pref) {
            NotificationPreference::updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'notification_type' => $pref['notification_type'],
                    'channel' => $pref['channel'],
                ],
                [
                    'enabled' => $pref['enabled'],
                ]
            );
        }

        return response()->json(['message' => 'Preferences updated successfully']);
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_token' => 'required|string',
            'device_type' => 'nullable|string|in:ios,android,web',
        ]);

        $this->pushService->registerDevice(
            $request->user(),
            $validated['device_token'],
            $validated['device_type'] ?? null
        );

        return response()->json(['message' => 'Device registered successfully']);
    }

    public function unregisterDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_token' => 'required|string',
        ]);

        $this->pushService->unregisterDevice(
            $request->user(),
            $validated['device_token']
        );

        return response()->json(['message' => 'Device unregistered successfully']);
    }
}

