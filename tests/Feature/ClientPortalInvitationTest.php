<?php

namespace Tests\Feature;

use App\Mail\ClientInviteMail;
use App\Models\RegistrationContinuationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contrato `docs/gap-simplesvet/specs/19-portal-do-cliente-spec.md` (Achado 1 + item 4):
 * convite de vínculo fora do fluxo de agendamento e status derivado do portal do cliente.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class ClientPortalInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->professional = User::factory()->professional()->create();
        Sanctum::actingAs($this->professional);
    }

    public function test_store_accepts_cpf_and_resolves_identity_via_tutor_identity_resolver(): void
    {
        $response = $this->postJson('/api/professional/clients', [
            'name' => 'Joana Silva',
            'cpf' => '390.533.447-05',
        ]);

        $response->assertStatus(201);

        $client = User::where('cpf', '39053344705')->firstOrFail();
        $this->assertTrue($client->isUnclaimed());
        $this->assertSame('pending', $client->registration_status);

        $this->assertDatabaseHas('professional_clients', [
            'professional_id' => $this->professional->id,
            'client_id' => $client->id,
        ]);
    }

    public function test_store_with_cpf_reuses_existing_tutor_instead_of_duplicating(): void
    {
        $existingTutor = User::factory()->tutor()->create(['cpf' => '39053344705']);

        $response = $this->postJson('/api/professional/clients', [
            'name' => 'Nome digitado diferente',
            'cpf' => '390.533.447-05',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.id', $existingTutor->id);
        $this->assertSame(1, User::where('cpf', '39053344705')->count());
    }

    public function test_store_without_email_or_cpf_is_rejected_with_422(): void
    {
        $this->postJson('/api/professional/clients', ['name' => 'Sem Identidade'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_invite_sends_continuation_link_to_unclaimed_client(): void
    {
        $client = User::factory()->tutor()->create(['password' => null, 'email' => 'novo@exemplo.com']);
        $this->linkClientToProfessional($client);

        $this->postJson("/api/professional/clients/{$client->id}/invite")->assertOk();

        $this->assertSame(1, RegistrationContinuationToken::where('user_id', $client->id)->count());
        Mail::assertSent(ClientInviteMail::class);
    }

    public function test_invite_rejects_client_with_already_active_account(): void
    {
        $client = User::factory()->tutor()->create();
        $this->linkClientToProfessional($client);

        $this->postJson("/api/professional/clients/{$client->id}/invite")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este cliente já tem conta ativa no 2pets — não há convite para reenviar.');

        Mail::assertNothingSent();
    }

    public function test_invite_rejects_client_without_email(): void
    {
        $client = User::factory()->tutor()->create(['password' => null, 'email' => null]);
        $this->linkClientToProfessional($client);

        $this->postJson("/api/professional/clients/{$client->id}/invite")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este cliente não tem e-mail cadastrado — informe um e-mail antes de enviar o convite.');
    }

    public function test_portal_status_reflects_no_account_invited_and_active(): void
    {
        $noAccountClient = User::factory()->tutor()->create(['password' => null, 'email' => 'a@exemplo.com']);
        $this->linkClientToProfessional($noAccountClient);

        $invitedClient = User::factory()->tutor()->create(['password' => null, 'email' => 'b@exemplo.com']);
        $this->linkClientToProfessional($invitedClient);
        $this->postJson("/api/professional/clients/{$invitedClient->id}/invite")->assertOk();

        $activeClient = User::factory()->tutor()->create();
        $this->linkClientToProfessional($activeClient);

        $this->getJson("/api/professional/clients/{$noAccountClient->id}/portal-status")
            ->assertOk()->assertJsonPath('state', 'no_account');

        $this->getJson("/api/professional/clients/{$invitedClient->id}/portal-status")
            ->assertOk()->assertJsonPath('state', 'invited');

        $this->getJson("/api/professional/clients/{$activeClient->id}/portal-status")
            ->assertOk()->assertJsonPath('state', 'active');
    }

    private function linkClientToProfessional(User $client): void
    {
        \App\Models\ProfessionalClient::create([
            'professional_id' => $this->professional->id,
            'client_id' => $client->id,
        ]);
    }
}
