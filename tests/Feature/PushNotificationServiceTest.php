<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notification\PushNotificationService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Verifies the FCM HTTP v1 contract without touching the database or Google.
 *
 * The project-wide migration `2026_04_23_000001_normalize_existing_cpfs.php`
 * uses Postgres-only `regexp_replace()` and breaks under the SQLite test
 * connection — that is unrelated to push notifications, so this test sidesteps
 * `RefreshDatabase` and overrides the service's `findSubscriptionsFor()` seam
 * with an in-memory collection.
 */
class PushNotificationServiceTest extends TestCase
{
    private const FAKE_OAUTH_TOKEN = 'ya29.fake-oauth-token-for-tests';

    private const FCM_V1_HOST = 'fcm.googleapis.com/v1/projects/*/messages:send';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.fcm.project_id', 'twopets-test-project');
        config()->set('services.fcm.service_account_json_path', 'storage/app/firebase-service-account.json');
        config()->set('services.fcm.service_account_json_base64', null);
        config()->set('services.fcm.server_key', null);

        // Pre-populate the OAuth cache so the service never instantiates Google\Auth.
        Cache::put('fcm_oauth_token', self::FAKE_OAUTH_TOKEN, 3000);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_send_posts_http_v1_payload_with_bearer_auth(): void
    {
        Http::fake([
            self::FCM_V1_HOST => Http::response(
                ['name' => 'projects/twopets-test-project/messages/123'],
                200
            ),
        ]);

        $user = $this->fakeUser(123);
        $sub = $this->fakeSubscription(1, 123, 'device-token-abc');

        $service = $this->makeServiceWithSubscriptions([$sub]);

        $service->send(
            $user,
            'Vacina vencendo',
            'A V10 do Rex vence amanhã.',
            ['pet_id' => 42, 'metadata' => ['kind' => 'vaccine'], 'is_urgent' => true]
        );

        Http::assertSent(function (ClientRequest $request) {
            $payload = $request->data();

            $this->assertSame('Bearer '.self::FAKE_OAUTH_TOKEN, $request->header('Authorization')[0]);
            $this->assertStringContainsString('/v1/projects/twopets-test-project/messages:send', $request->url());

            $message = $payload['message'] ?? null;
            $this->assertNotNull($message);
            $this->assertSame('device-token-abc', $message['token']);
            $this->assertSame('Vacina vencendo', $message['notification']['title']);
            $this->assertSame('A V10 do Rex vence amanhã.', $message['notification']['body']);

            // FCM HTTP v1 requires every data value to be a string.
            $this->assertSame('42', $message['data']['pet_id']);
            $this->assertSame('{"kind":"vaccine"}', $message['data']['metadata']);
            $this->assertSame('true', $message['data']['is_urgent']);

            $this->assertSame('high', $message['android']['priority']);
            $this->assertSame('default', $message['apns']['payload']['aps']['sound']);
            $this->assertSame(1, $message['apns']['payload']['aps']['badge']);

            return true;
        });
    }

    public function test_unregistered_token_response_deletes_subscription(): void
    {
        Http::fake([
            self::FCM_V1_HOST => Http::response([
                'error' => [
                    'code' => 404,
                    'message' => 'Requested entity was not found.',
                    'status' => 'NOT_FOUND',
                ],
            ], 404),
        ]);

        $deleted = false;
        $user = $this->fakeUser(7);
        $sub = $this->fakeSubscription(99, 7, 'dead-token');
        $sub->shouldReceive('delete')
            ->once()
            ->andReturnUsing(function () use (&$deleted) {
                $deleted = true;

                return true;
            });

        $service = $this->makeServiceWithSubscriptions([$sub]);
        $service->send($user, 'Hi', 'Hello');

        $this->assertTrue($deleted, 'Expected dead device token to be deleted on FCM 404 / NOT_FOUND.');
        Http::assertSentCount(1);
    }

    public function test_send_skips_when_not_configured(): void
    {
        config()->set('services.fcm.project_id', null);
        Http::fake();

        $user = $this->fakeUser(5);
        // Even if subs exist, they should never be loaded — pass empty.
        $service = $this->makeServiceWithSubscriptions([]);
        $service->send($user, 'Hi', 'Hello');

        Http::assertNothingSent();
    }

    private function fakeUser(int $id): User
    {
        $user = new User;
        $user->id = $id;

        return $user;
    }

    /**
     * Returns a partial Mockery mock that quacks like a PushSubscription.
     * Using Mockery (rather than `new PushSubscription()`) lets us assert `delete()`
     * without persisting the row.
     */
    private function fakeSubscription(int $id, int $userId, string $token): PushSubscription
    {
        $sub = Mockery::mock(PushSubscription::class)->makePartial();
        $sub->id = $id;
        $sub->user_id = $userId;
        $sub->device_token = $token;

        return $sub;
    }

    /**
     * Subclasses PushNotificationService to override the DB-touching seam.
     *
     * @param  array<int, PushSubscription>  $subscriptions
     */
    private function makeServiceWithSubscriptions(array $subscriptions): PushNotificationService
    {
        return new class($subscriptions) extends PushNotificationService
        {
            /** @var Collection<int, PushSubscription> */
            private Collection $stub;

            /** @param array<int, PushSubscription> $subs */
            public function __construct(array $subs)
            {
                parent::__construct();
                $this->stub = collect($subs);
            }

            protected function findSubscriptionsFor(User $user): Collection
            {
                return $this->stub;
            }
        };
    }
}
