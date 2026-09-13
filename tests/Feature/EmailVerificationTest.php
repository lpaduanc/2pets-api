<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_token_verifies_email_and_allows_login(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'email_verification_token' => 'valid-token-123',
            'email_verification_sent_at' => now(),
        ]);

        $response = $this->postJson('/api/verify-email/valid-token-123');

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'name', 'email', 'email_verified'],
            ])
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token')
            ->assertJsonMissingPath('user.email_verification_token')
            ->assertJsonPath('user.email_verified', true);

        $user->refresh();

        $this->assertTrue($user->email_verified);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_token);
    }

    public function test_invalid_token_returns_not_found(): void
    {
        $response = $this->postJson('/api/verify-email/does-not-exist');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Token de verificação inválido.']);
    }

    /**
     * Regressão do P2 da auditoria de cadastro: token sem TTL nunca expirava.
     */
    public function test_expired_token_is_rejected_and_does_not_verify_the_email(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'email_verification_token' => 'expired-token-123',
            'email_verification_sent_at' => now()->subHours(25),
        ]);

        $response = $this->postJson('/api/verify-email/expired-token-123');

        $response->assertStatus(410)->assertJsonPath('expired', true);

        $user->refresh();
        $this->assertFalse($user->email_verified);
        $this->assertNotNull($user->email_verification_token);
    }

    public function test_token_just_inside_the_ttl_window_still_verifies(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'email_verification_token' => 'still-valid-token',
            'email_verification_sent_at' => now()->subHours(23),
        ]);

        $response = $this->postJson('/api/verify-email/still-valid-token');

        $response->assertOk();
        $this->assertTrue($user->refresh()->email_verified);
    }

    /**
     * Regressão do P2 da auditoria de cadastro: `POST /verify-email/{token}` era o único
     * endpoint público de auth sem `throttle:` nenhum.
     */
    public function test_verify_email_endpoint_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/verify-email/does-not-exist')->assertStatus(404);
        }

        $this->postJson('/api/verify-email/does-not-exist')->assertStatus(429);
    }
}
