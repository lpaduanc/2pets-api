<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Não-regressão: `LgpdController::deleteAccount` (direito ao esquecimento, LGPD Art. 18) é
 * um caminho legal separado da desativação voluntária (`AccountDeactivationService`) — ver
 * `app/Http/Controllers/Api/LgpdController.php`. Este teste trava o comportamento esperado: anonimiza, soft-deleta e barra o login pelo mesmo mecanismo
 * de sempre (a conta some das queries por `SoftDeletes`).
 */
class LgpdDeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_account_anonymizes_and_soft_deletes_the_user(): void
    {
        $tutor = User::factory()->tutor()->create([
            'name' => 'Fulano de Tal',
            'email' => 'fulano@example.com',
            'cpf' => '52998224725',
        ]);
        Pet::factory()->create(['user_id' => $tutor->id]);

        Sanctum::actingAs($tutor);

        $response = $this->postJson('/api/lgpd/delete-account', [
            'password' => 'password',
            'confirmation' => 'DELETAR',
        ]);

        $response->assertOk();

        $this->assertSoftDeleted('users', ['id' => $tutor->id]);

        $raw = \Illuminate\Support\Facades\DB::table('users')->where('id', $tutor->id)->first();
        $this->assertSame('Usuario Removido', $raw->name);
        $this->assertStringContainsString('deleted_', $raw->email);
        $this->assertNull($raw->cpf);
    }

    public function test_anonymized_account_cannot_login_and_does_not_leak_existence(): void
    {
        $tutor = User::factory()->tutor()->create(['email' => 'sumiu@example.com']);
        Sanctum::actingAs($tutor);

        $deleteResponse = $this->postJson('/api/lgpd/delete-account', [
            'password' => 'password',
            'confirmation' => 'DELETAR',
        ]);

        $deleteResponse->assertOk();

        $response = $this->postJson('/api/login', [
            'email' => 'sumiu@example.com',
            'password' => 'password',
        ]);

        // Mesma resposta genérica de credenciais inválidas — nunca "conta desativada".
        $response->assertStatus(401);
        $this->assertArrayNotHasKey('account_deactivated', $response->json());
    }

    public function test_delete_account_requires_correct_password(): void
    {
        $tutor = User::factory()->tutor()->create();
        Sanctum::actingAs($tutor);

        $response = $this->postJson('/api/lgpd/delete-account', [
            'password' => 'wrong-password',
            'confirmation' => 'DELETAR',
        ]);

        $response->assertStatus(403);
        $this->assertNull($tutor->fresh()->deleted_at);
    }
}
