<?php

namespace App\Services\Notification;

use App\Models\PushSubscription;
use App\Models\User;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications via Firebase Cloud Messaging using the HTTP v1 API.
 *
 * The legacy HTTP API (https://fcm.googleapis.com/fcm/send) was shut down by
 * Google on 2024-06-20. This service now uses:
 *   POST https://fcm.googleapis.com/v1/projects/{project_id}/messages:send
 * with an OAuth2 Bearer token minted from a service account JSON.
 *
 * To enable FCM HTTP v1:
 *   1. Firebase Console → Project Settings → Service Accounts → Generate new private key.
 *   2. Save the JSON as storage/app/firebase-service-account.json (already gitignored).
 *   3. Set FCM_PROJECT_ID and FCM_SERVICE_ACCOUNT_JSON_PATH in .env
 *      (or use FCM_SERVICE_ACCOUNT_JSON_BASE64 for ephemeral environments).
 *   4. Test with: php artisan tinker → app(PushNotificationService::class)
 *      ->send(User::first(), 'Test', 'Hello');
 *
 * Note: this class is intentionally NOT `final` so tests can override
 * `findSubscriptionsFor()` to avoid touching the database.
 */
class PushNotificationService
{
    private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const OAUTH_CACHE_KEY = 'fcm_oauth_token';

    private const OAUTH_CACHE_TTL_SECONDS = 3000; // 50 min — token is valid for 60 min

    private const FCM_V1_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private ?string $projectId;

    private ?string $serviceAccountJsonPath;

    private ?string $serviceAccountJsonBase64;

    private ?string $legacyServerKey;

    public function __construct()
    {
        $this->projectId = config('services.fcm.project_id');
        $this->serviceAccountJsonPath = config('services.fcm.service_account_json_path');
        $this->serviceAccountJsonBase64 = config('services.fcm.service_account_json_base64');
        $this->legacyServerKey = config('services.fcm.server_key');

        if ($this->legacyServerKey && ! $this->projectId) {
            Log::warning(
                'FCM_SERVER_KEY is set but the legacy FCM HTTP API was shut down on 2024-06-20. '
                .'Migrate to HTTP v1: set FCM_PROJECT_ID and FCM_SERVICE_ACCOUNT_JSON_PATH.'
            );
        }
    }

    public function send(User $user, string $title, string $body, array $data = []): void
    {
        if (! $this->isConfigured()) {
            Log::warning('FCM HTTP v1 not configured; skipping push.', [
                'user_id' => $user->id,
                'project_id_set' => (bool) $this->projectId,
                'credentials_set' => (bool) ($this->serviceAccountJsonPath || $this->serviceAccountJsonBase64),
            ]);

            return;
        }

        $subscriptions = $this->findSubscriptionsFor($user);

        if ($subscriptions->isEmpty()) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            $this->sendToDevice($subscription, $title, $body, $data);
        }
    }

    /**
     * Test seam: override in unit tests to return an in-memory collection
     * without hitting the database.
     *
     * @return Collection<int, PushSubscription>
     */
    protected function findSubscriptionsFor(User $user): Collection
    {
        return PushSubscription::where('user_id', $user->id)->get();
    }

    private function sendToDevice(
        PushSubscription $subscription,
        string $title,
        string $body,
        array $data,
        bool $isRetryAfterAuthFailure = false
    ): void {
        try {
            $token = $this->getOAuthToken();
        } catch (\Throwable $e) {
            // Don't crash the queue worker — log and move on. Retries on next dispatch.
            Log::error('Failed to mint FCM OAuth token; aborting push.', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $endpoint = sprintf(self::FCM_V1_ENDPOINT, $this->projectId);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ])->post($endpoint, [
                'message' => $this->buildMessage(
                    $subscription->device_token,
                    $title,
                    $body,
                    $data
                ),
            ]);
        } catch (\Throwable $e) {
            Log::error('FCM HTTP v1 push transport error', [
                'device_token_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $bodyText = $response->body();

        // 401: cached OAuth token rotated/expired between mint and use. Drop cache and retry once.
        if ($status === 401 && ! $isRetryAfterAuthFailure) {
            Cache::forget(self::OAUTH_CACHE_KEY);
            Log::warning('FCM HTTP v1 returned 401, retrying once with fresh OAuth token.', [
                'device_token_id' => $subscription->id,
            ]);
            $this->sendToDevice($subscription, $title, $body, $data, true);

            return;
        }

        // 404 / NOT_FOUND or 400 with UNREGISTERED → device token is dead. Remove it.
        $errorStatus = data_get(json_decode($bodyText, true), 'error.status');
        if ($status === 404 || $errorStatus === 'UNREGISTERED' || $errorStatus === 'NOT_FOUND') {
            Log::info('FCM device token unregistered; removing subscription.', [
                'device_token_id' => $subscription->id,
                'user_id' => $subscription->user_id,
            ]);
            $subscription->delete();

            return;
        }

        Log::error('FCM HTTP v1 push notification failed', [
            'device_token_id' => $subscription->id,
            'status' => $status,
            'response' => $bodyText,
        ]);
    }

    /**
     * Build the FCM HTTP v1 `message` payload. All `data` values must be strings —
     * arrays/objects are JSON-encoded, scalars are stringified.
     */
    private function buildMessage(
        string $deviceToken,
        string $title,
        string $body,
        array $data
    ): array {
        return [
            'token' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $this->stringifyData($data),
            'android' => [
                'priority' => 'high',
            ],
            'apns' => [
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                        'badge' => 1,
                    ],
                ],
            ],
        ];
    }

    /**
     * FCM HTTP v1 requires every `data` value to be a string. Arrays and objects
     * become JSON strings; scalars become string casts; nulls become empty strings.
     */
    private function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $out[(string) $key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $out[(string) $key] = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $out[(string) $key] = '';
            } else {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Returns a cached OAuth2 access token (TTL 50min). Multiple workers may
     * concurrently mint tokens at boot — Google permits this and the older
     * tokens stay valid for the full hour.
     */
    private function getOAuthToken(): string
    {
        return Cache::remember(self::OAUTH_CACHE_KEY, self::OAUTH_CACHE_TTL_SECONDS, function (): string {
            $serviceAccount = $this->loadServiceAccountJson();
            $credentials = new ServiceAccountCredentials(self::OAUTH_SCOPE, $serviceAccount);
            $token = $credentials->fetchAuthToken();

            if (! is_array($token) || empty($token['access_token'])) {
                throw new \RuntimeException('FCM OAuth response missing access_token');
            }

            return $token['access_token'];
        });
    }

    /**
     * Returns the service-account JSON as an associative array. Prefers the file
     * path; falls back to base64-encoded env var (useful for managed hosts).
     *
     * @return array<string, mixed>
     */
    private function loadServiceAccountJson(): array
    {
        if ($this->serviceAccountJsonPath) {
            $path = $this->serviceAccountJsonPath;
            // Allow paths relative to the Laravel base path.
            if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
                $path = base_path($path);
            }
            if (! is_readable($path)) {
                throw new \RuntimeException("FCM service-account JSON not readable at: {$path}");
            }
            $decoded = json_decode((string) file_get_contents($path), true);
        } elseif ($this->serviceAccountJsonBase64) {
            $raw = base64_decode($this->serviceAccountJsonBase64, true);
            if ($raw === false) {
                throw new \RuntimeException('FCM_SERVICE_ACCOUNT_JSON_BASE64 is not valid base64');
            }
            $decoded = json_decode($raw, true);
        } else {
            throw new \RuntimeException(
                'FCM credentials missing: set FCM_SERVICE_ACCOUNT_JSON_PATH or FCM_SERVICE_ACCOUNT_JSON_BASE64'
            );
        }

        if (! is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            throw new \RuntimeException('FCM service-account JSON is malformed (missing client_email/private_key)');
        }

        return $decoded;
    }

    private function isConfigured(): bool
    {
        return $this->projectId
            && ($this->serviceAccountJsonPath || $this->serviceAccountJsonBase64);
    }

    /**
     * Achado junto com a migration ausente de `push_subscriptions`: a chave de upsert
     * tinha que ser `device_token` sozinho, não `(user_id, device_token)`. Com a
     * composta, o MESMO aparelho trocando de dono (logout de A, login de B no mesmo
     * celular) criava uma SEGUNDA linha em vez de reassociar a existente — e como
     * `device_token` é único entre linhas vivas (mesma migration), essa segunda linha
     * violaria a constraint em vez de simplesmente atualizar o `user_id`.
     */
    public function registerDevice(User $user, string $deviceToken, ?string $deviceType = null): void
    {
        PushSubscription::updateOrCreate(
            ['device_token' => $deviceToken],
            [
                'user_id' => $user->id,
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
