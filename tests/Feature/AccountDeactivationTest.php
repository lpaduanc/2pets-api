<?php

namespace Tests\Feature;

use App\Enums\DeactivationReason;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Pet;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Decisão do dono do produto (2026-09-13): desativar NUNCA apaga ou altera dado nenhum além do
 * próprio estado da conta — é a diferença deliberada com `LgpdController::deleteAccount`
 * (anonimização a pedido do titular, testado à parte em `LgpdDeleteAccountTest`).
 */
class AccountDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private function createTutorWithHistory(): array
    {
        $tutor = User::factory()->tutor()->create([
            'password' => Hash::make('secret1234'),
            'cpf' => '52998224725',
        ]);
        $professional = User::factory()->professional()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        $vaccination = Vaccination::create([
            'pet_id' => $pet->id,
            'professional_id' => $professional->id,
            'vaccine_name' => 'V10',
            'application_date' => now()->subMonth(),
        ]);

        $appointment = Appointment::create([
            'professional_id' => $professional->id,
            'client_id' => $tutor->id,
            'pet_id' => $pet->id,
            'appointment_date' => now()->addWeek()->toDateString(),
            'appointment_time' => '10:00:00',
        ]);

        $invoice = Invoice::create([
            'professional_id' => $professional->id,
            'client_id' => $tutor->id,
            'invoice_number' => 'INV-'.$tutor->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['description' => 'Consulta', 'quantity' => 1, 'price' => 150]],
            'subtotal' => 150,
            'total' => 150,
        ]);

        return compact('tutor', 'professional', 'pet', 'vaccination', 'appointment', 'invoice');
    }

    public function test_deactivating_does_not_delete_or_alter_any_data(): void
    {
        $fixture = $this->createTutorWithHistory();
        $tutor = $fixture['tutor'];

        $petsBefore = Pet::count();
        $vaccinationsBefore = Vaccination::count();
        $appointmentsBefore = Appointment::count();
        $invoicesBefore = Invoice::count();

        Sanctum::actingAs($tutor);

        $response = $this->postJson('/api/account/deactivate', [
            'password' => 'secret1234',
            'reason' => DeactivationReason::NOT_USING->value,
        ]);

        $response->assertOk();

        $this->assertSame($petsBefore, Pet::count());
        $this->assertSame($vaccinationsBefore, Vaccination::count());
        $this->assertSame($appointmentsBefore, Appointment::count());
        $this->assertSame($invoicesBefore, Invoice::count());

        $tutor->refresh();
        $this->assertNotNull($tutor->deactivated_at);
        $this->assertNull($tutor->deleted_at, 'Desativar não é soft delete.');
        $this->assertSame($fixture['tutor']->name, $tutor->name);
        $this->assertSame($fixture['tutor']->email, $tutor->email);
        $this->assertSame('52998224725', $tutor->cpf);
        $this->assertTrue(Hash::check('secret1234', $tutor->password));
    }

    public function test_deactivating_requires_correct_password(): void
    {
        $tutor = User::factory()->tutor()->create(['password' => Hash::make('secret1234')]);
        Sanctum::actingAs($tutor);

        $response = $this->postJson('/api/account/deactivate', [
            'password' => 'wrong-password',
            'reason' => DeactivationReason::NOT_USING->value,
        ]);

        $response->assertStatus(403);
        $this->assertNull($tutor->fresh()->deactivated_at);
    }

    public function test_deactivating_revokes_existing_tokens(): void
    {
        $tutor = User::factory()->tutor()->create(['password' => Hash::make('secret1234')]);
        $token = $tutor->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/account/deactivate', [
                'password' => 'secret1234',
                'reason' => DeactivationReason::NOT_USING->value,
            ])->assertOk();

        $this->assertSame(0, $tutor->tokens()->count());
    }

    public function test_deactivated_user_is_blocked_from_login_with_reactivation_message(): void
    {
        $tutor = User::factory()->tutor()->create([
            'email' => 'desativada@test.com',
            'password' => Hash::make('secret1234'),
            'deactivated_at' => now(),
            'deactivation_reason' => DeactivationReason::NOT_USING,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'desativada@test.com',
            'password' => 'secret1234',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'account_deactivated' => true,
                'can_reactivate' => true,
            ]);
    }

    public function test_reactivation_restores_access_and_keeps_data(): void
    {
        $fixture = $this->createTutorWithHistory();
        $tutor = $fixture['tutor'];
        $tutor->forceFill([
            'deactivated_at' => now()->subDay(),
            'deactivation_reason' => DeactivationReason::NOT_USING,
            'deactivated_by' => $tutor->id,
        ])->save();

        $response = $this->postJson('/api/account/reactivate', [
            'email' => $tutor->email,
            'password' => 'secret1234',
        ]);

        $response->assertOk()->assertJsonStructure(['access_token', 'token_type', 'user']);

        $tutor->refresh();
        $this->assertNull($tutor->deactivated_at);
        $this->assertNull($tutor->deactivation_reason);
        $this->assertNotNull($tutor->reactivated_at);

        // Dados continuam lá.
        $this->assertSame(1, Pet::where('user_id', $tutor->id)->count());
        $this->assertSame(1, Vaccination::where('pet_id', $fixture['pet']->id)->count());

        // O token novo funciona de verdade.
        $token = $response->json('access_token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_reactivation_fails_with_wrong_password(): void
    {
        $tutor = User::factory()->tutor()->create([
            'password' => Hash::make('secret1234'),
            'deactivated_at' => now(),
            'deactivation_reason' => DeactivationReason::NOT_USING,
        ]);

        $response = $this->postJson('/api/account/reactivate', [
            'email' => $tutor->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $this->assertNotNull($tutor->fresh()->deactivated_at);
    }

    public function test_suspended_and_deactivated_account_cannot_self_reactivate(): void
    {
        $tutor = User::factory()->tutor()->create([
            'password' => Hash::make('secret1234'),
            'is_suspended' => true,
            'deactivated_at' => now(),
            'deactivation_reason' => DeactivationReason::NOT_USING,
        ]);

        $response = $this->postJson('/api/account/reactivate', [
            'email' => $tutor->email,
            'password' => 'secret1234',
        ]);

        $response->assertStatus(403)->assertJson(['account_suspended' => true]);
        $this->assertNotNull($tutor->fresh()->deactivated_at);
    }
}
