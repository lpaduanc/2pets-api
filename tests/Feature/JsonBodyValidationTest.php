<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Cobre o `ValidateJsonRequestBody`: corpo JSON malformado (ou JSON válido
 * mas não-objeto) tinha que virar 400 antes de qualquer Form Request rodar.
 *
 * Antes desta correção, `Content-Type: application/json` com corpo
 * ilegível fazia `Request::json()` cair em `(array) json_decode(...)`, que
 * vira `[]` silenciosamente — como `UpdateProfileRequest` é todo
 * `sometimes` (patch parcial legítimo), a request "vazia" passava validação
 * sem validar nada, nada era gravado, e o endpoint respondia 200 como se
 * tivesse tido sucesso.
 */
class JsonBodyValidationTest extends TestCase
{
    use RefreshDatabase;

    private function putRawJson(string $uri, string $rawContent): TestResponse
    {
        $server = $this->transformHeadersToServerVars([
            'CONTENT_TYPE' => 'application/json',
            'Accept' => 'application/json',
        ]);

        return $this->call('PUT', $uri, [], [], [], $server, $rawContent);
    }

    public function test_malformed_json_body_is_rejected_with_400_and_does_not_write(): void
    {
        $user = User::factory()->tutor()->create(['phone' => '(11) 90000-0000']);

        Sanctum::actingAs($user);

        // Vírgula sobrando — JSON sintaticamente inválido.
        $response = $this->putRawJson('/api/profile', '{"phone":"(11) 98888-8888",}');

        $response->assertStatus(400);
        $response->assertJsonStructure(['message']);

        $user->refresh();
        $this->assertSame('(11) 90000-0000', $user->phone);
    }

    public function test_valid_partial_json_body_still_updates_profile(): void
    {
        $user = User::factory()->tutor()->create(['phone' => '(11) 90000-0000']);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/profile', ['phone' => '(11) 98888-8888']);

        $response->assertOk();

        $user->refresh();
        $this->assertSame('(11) 98888-8888', $user->phone);
    }

    public function test_request_without_body_is_not_rejected(): void
    {
        $user = User::factory()->tutor()->create();

        Sanctum::actingAs($user);

        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json']);

        $response = $this->call('PUT', '/api/profile', [], [], [], $server, null);

        $response->assertOk();
    }

    public function test_json_content_type_with_empty_body_is_not_rejected(): void
    {
        $user = User::factory()->tutor()->create();

        Sanctum::actingAs($user);

        $response = $this->putRawJson('/api/profile', '');

        $response->assertOk();
    }

    /**
     * Decisão documentada: JSON sintaticamente válido cujo valor de topo não
     * é um objeto (`"texto"`, `42`, `null`, `[1,2]`) não tem chave nenhuma
     * para virar input de formulário — é o mesmo bug de "request vazia" por
     * outro caminho. Tratado como 400, não como corpo vazio.
     */
    public function test_valid_json_array_instead_of_object_is_rejected_with_400(): void
    {
        $user = User::factory()->tutor()->create(['phone' => '(11) 90000-0000']);

        Sanctum::actingAs($user);

        $response = $this->putRawJson('/api/profile', '[1,2,3]');

        $response->assertStatus(400);

        $user->refresh();
        $this->assertSame('(11) 90000-0000', $user->phone);
    }

    public function test_valid_json_scalar_instead_of_object_is_rejected_with_400(): void
    {
        $user = User::factory()->tutor()->create();

        Sanctum::actingAs($user);

        $response = $this->putRawJson('/api/profile', '"texto"');

        $response->assertStatus(400);
    }

    public function test_valid_json_null_instead_of_object_is_rejected_with_400(): void
    {
        $user = User::factory()->tutor()->create();

        Sanctum::actingAs($user);

        $response = $this->putRawJson('/api/profile', 'null');

        $response->assertStatus(400);
    }

    // ---------------------------------------------------------------
    // Webhooks — confirmam que o middleware global não quebra o payload
    // JSON de objeto que Stripe e Mercado Pago já mandavam.
    // ---------------------------------------------------------------

    public function test_stripe_webhook_still_accepts_its_json_object_payload(): void
    {
        // `WebhookController` recebe `PaymentGatewayInterface` no construtor, mesmo
        // `stripe()` não usando `$this->gateway` — e a interface não tem binding em
        // nenhum ServiceProvider (achado de carona, fora do escopo desta tarefa: hoje,
        // em dev real, os dois webhooks 500 mesmo com payload válido). O mock aqui só
        // satisfaz a resolução do container, igual ao padrão já usado em PaymentTest.
        $this->app->instance(PaymentGatewayInterface::class, Mockery::mock(PaymentGatewayInterface::class));

        $response = $this->postJson('/api/webhooks/stripe', [
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_123']],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('payment_webhooks', [
            'gateway' => 'stripe',
            'event_type' => 'payment_intent.succeeded',
            'gateway_payment_id' => 'pi_123',
        ]);
    }

    public function test_mercadopago_webhook_still_accepts_its_json_object_payload(): void
    {
        $mock = Mockery::mock(PaymentGatewayInterface::class);
        $mock->shouldReceive('parseWebhookPayload')
            ->andReturn(['event_type' => 'payment', 'payment_id' => 'mp_123']);

        $this->app->instance(PaymentGatewayInterface::class, $mock);

        $response = $this->postJson('/api/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => 'mp_123'],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('payment_webhooks', [
            'gateway' => 'mercadopago',
            'event_type' => 'payment',
            'gateway_payment_id' => 'mp_123',
        ]);
    }
}
