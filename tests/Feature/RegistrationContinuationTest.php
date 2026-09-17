<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\User;
use App\Services\Registration\RegistrationContinuationTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET/POST /register/continue/{token}` — contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §6/§7.1.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`. A
 * cobertura foi validada manualmente via `curl` (ver relatório da tarefa).
 */
class RegistrationContinuationTest extends TestCase
{
    use RefreshDatabase;

    private function unclaimedTutor(): User
    {
        return User::create([
            'name' => 'Carlos Oliveira',
            'cpf' => '39053344705',
            'email' => 'carlos@exemplo.com',
            'password' => null,
            'user_type' => 'tutor',
            'role' => 'tutor',
            'registration_status' => 'pending',
            'profile_completed' => false,
        ]);
    }

    public function test_preview_shows_identity_and_pets_without_consuming_the_token(): void
    {
        $tutor = $this->unclaimedTutor();
        Pet::factory()->create(['user_id' => $tutor->id, 'name' => 'Toddy', 'species' => 'dog']);
        $token = app(RegistrationContinuationTokenService::class)->issue($tutor);

        $response = $this->getJson("/api/register/continue/{$token}");

        $response->assertOk()
            ->assertJsonPath('data.name', 'Carlos Oliveira')
            ->assertJsonPath('data.cpf', '39053344705')
            ->assertJsonPath('data.pets.0.name', 'Toddy');

        // Não consome: um segundo GET continua funcionando.
        $this->getJson("/api/register/continue/{$token}")->assertOk();
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->getJson('/api/register/continue/token-que-nao-existe')->assertStatus(404);
    }

    public function test_complete_sets_password_and_issues_a_token(): void
    {
        $tutor = $this->unclaimedTutor();
        $token = app(RegistrationContinuationTokenService::class)->issue($tutor);

        $response = $this->postJson("/api/register/continue/{$token}", [
            'password' => 'senha12345',
            'password_confirmation' => 'senha12345',
        ]);

        $response->assertOk()->assertJsonStructure(['access_token', 'token_type', 'user']);

        $fresh = $tutor->fresh();
        $this->assertNotNull($fresh->password);
        $this->assertSame('approved', $fresh->registration_status);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('senha12345', $fresh->password));
    }

    public function test_token_is_single_use(): void
    {
        $tutor = $this->unclaimedTutor();
        $token = app(RegistrationContinuationTokenService::class)->issue($tutor);

        $this->postJson("/api/register/continue/{$token}", [
            'password' => 'senha12345',
            'password_confirmation' => 'senha12345',
        ])->assertOk();

        $this->postJson("/api/register/continue/{$token}", [
            'password' => 'outrasenha1',
            'password_confirmation' => 'outrasenha1',
        ])->assertStatus(404);
    }

    public function test_resending_invalidates_the_previous_token(): void
    {
        $tutor = $this->unclaimedTutor();
        $service = app(RegistrationContinuationTokenService::class);

        $firstToken = $service->issue($tutor);
        $service->issue($tutor);

        $this->getJson("/api/register/continue/{$firstToken}")->assertStatus(404);
    }

    public function test_unclaimed_account_cannot_login_with_email_and_password(): void
    {
        $tutor = $this->unclaimedTutor();

        $this->postJson('/api/login', [
            'email' => $tutor->email,
            'password' => 'qualquer-coisa',
        ])->assertStatus(401);
    }
}
