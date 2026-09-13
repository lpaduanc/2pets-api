<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfessionalClientProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->professional = User::factory()->professional()->create();
    }

    /**
     * Bug: `ProfessionalClientController::store` created the `User` with a hardcoded
     * `bcrypt('password')`, no role/user_type/registration_status, and silently dropped the
     * validated `phone`/`address` fields. The new account must never have a guessable
     * password, must be reconciled to the `tutor` Spatie role, and must persist the fields
     * the request validated.
     */
    public function test_store_creates_client_without_guessable_password_and_with_tutor_role(): void
    {
        Mail::fake();

        Sanctum::actingAs($this->professional);

        $response = $this->postJson('/api/professional/clients', [
            'name' => 'Maria Tutora',
            'email' => 'maria.tutora@example.com',
            'phone' => '11988887777',
            'address' => 'Rua das Flores, 123',
        ]);

        $response->assertStatus(201);

        $client = User::where('email', 'maria.tutora@example.com')->firstOrFail();

        $this->assertFalse(Hash::check('password', $client->password));
        $this->assertSame('tutor', $client->user_type);
        $this->assertTrue($client->hasRole('tutor'));
        $this->assertSame('11988887777', $client->phone);
        $this->assertSame('Rua das Flores, 123', $client->address);

        // A password-set link must have been issued so the person can access the account.
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'maria.tutora@example.com']);
    }

    public function test_store_links_existing_account_instead_of_duplicating_by_email(): void
    {
        $existingTutor = User::factory()->tutor()->create(['email' => 'ja.cadastrado@example.com']);

        Sanctum::actingAs($this->professional);

        $response = $this->postJson('/api/professional/clients', [
            'name' => 'Nome Diferente Digitado Pelo Profissional',
            'email' => 'ja.cadastrado@example.com',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.id', $existingTutor->id);

        $this->assertSame(1, User::where('email', 'ja.cadastrado@example.com')->count());
    }

    /**
     * Bug: um cliente cadastrado manualmente pelo profissional não tinha appointment,
     * invoice nem PetVetAccess ativo — nasceu HTTP 201, mas sumia da própria listagem.
     */
    public function test_manually_created_client_appears_in_listing(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->professional);

        $this->postJson('/api/professional/clients', [
            'name' => 'Carlos Menezes',
            'email' => 'carlos.menezes@example.com',
        ])->assertStatus(201);

        $response = $this->getJson('/api/professional/clients')->assertOk();

        $this->assertContains(
            'carlos.menezes@example.com',
            $response->json('data.*.email'),
        );
    }

    /**
     * Um tutor que é cliente manual E tem grant de PetVetAccess ativo não pode aparecer
     * duas vezes — a query é um único WHERE...OR, não uma união de listas.
     */
    public function test_client_matching_manual_link_and_active_grant_is_not_duplicated(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->professional);

        $this->postJson('/api/professional/clients', [
            'name' => 'Ana Dupla',
            'email' => 'ana.dupla@example.com',
        ])->assertStatus(201);

        $tutor = User::where('email', 'ana.dupla@example.com')->firstOrFail();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        PetVetAccess::factoryCreatePending($this->professional, $pet, VetAccessLevel::READ)
            ->accept(VetAccessLevel::READ);

        $response = $this->getJson('/api/professional/clients')->assertOk();

        $emails = collect($response->json('data'))->pluck('email')->filter(fn ($email) => $email === 'ana.dupla@example.com');

        $this->assertCount(1, $emails);
    }

    public function test_destroy_removes_manual_link_without_deleting_the_user_account(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->professional);

        $this->postJson('/api/professional/clients', [
            'name' => 'Beatriz Vínculo',
            'email' => 'beatriz.vinculo@example.com',
        ])->assertStatus(201);

        $client = User::where('email', 'beatriz.vinculo@example.com')->firstOrFail();

        $this->deleteJson("/api/professional/clients/{$client->id}")->assertNoContent();

        $this->assertNotNull(User::find($client->id), 'A conta do cliente não pode ser apagada ao desvincular.');
        $this->assertSoftDeleted(
            'professional_clients',
            ['professional_id' => $this->professional->id, 'client_id' => $client->id],
        );

        $response = $this->getJson('/api/professional/clients')->assertOk();
        $this->assertNotContains('beatriz.vinculo@example.com', $response->json('data.*.email'));
    }

    /**
     * "Cliente" derivado de agendamento/fatura/grant não tem linha própria para remover —
     * apagar a conta do usuário inteira era o bug real que existia aqui antes.
     */
    public function test_destroy_rejects_client_without_manual_link(): void
    {
        Sanctum::actingAs($this->professional);

        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);
        PetVetAccess::factoryCreatePending($this->professional, $pet, VetAccessLevel::READ)
            ->accept(VetAccessLevel::READ);

        $response = $this->deleteJson("/api/professional/clients/{$tutor->id}");

        $response->assertStatus(422);
        $this->assertNotNull(User::find($tutor->id));
    }
}
