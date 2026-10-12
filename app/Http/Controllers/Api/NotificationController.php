<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\NotificationPreference;
use App\Services\Notification\NotificationService;
use App\Services\Notification\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function __construct(
        private readonly PushNotificationService $pushService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * `per_page` existe para o painel do sino, que mostra só as 5 mais recentes.
     * `meta.unread_count` vem junto para o painel abrir com lista e contador na MESMA
     * leitura — duas requisições separadas podiam discordar entre si.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        $user = $request->user();

        $notifications = $user
            ->notifications()
            ->orderBy('created_at', 'desc')
            ->paginate($validated['per_page'] ?? 20);

        return NotificationResource::collection($notifications)->additional([
            'meta' => ['unread_count' => $user->unreadNotifications()->count()],
        ]);
    }

    /**
     * Só o número: o contador do sino chama isto periodicamente. Antes carregava TODAS
     * as não lidas (cruas, sem o Resource) para depois contar em PHP — ninguém usava a lista.
     */
    public function unread(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $request->user()->unreadNotifications()->count(),
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
            $defaults = $this->notificationService->getDefaultChannels($type);

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

    /**
     * Push não segue `getDefaultChannels()`: todo `InAppNotification` tenta o `FcmChannel`,
     * e o `PushPreferenceGate` só bloqueia quando existe opt-out gravado. Sem linha, o push
     * sai — então a tela precisa mostrá-lo ligado.
     *
     * @param  list<NotificationChannel>  $defaults
     */
    private function isEnabledByDefault(NotificationChannel $channel, array $defaults): bool
    {
        return $channel === NotificationChannel::PUSH || in_array($channel, $defaults, true);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => 'required|array',
            'preferences.*.notification_type' => ['required', Rule::enum(NotificationType::class)],
            'preferences.*.channel' => ['required', Rule::enum(NotificationChannel::class)],
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
