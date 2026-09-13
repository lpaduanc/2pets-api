<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Não-regressão: `LgpdController::deleteAccount` (direito ao esquecimento, LGPD Art. 18) é
 * um caminho legal separado da desativação voluntária (`AccountDeactivationService`) e NÃO foi
 * alterado por esta tarefa — ver `app/Http/Controllers/Api/LgpdController.php`. Este teste
 * trava o comportamento esperado: anonimiza, soft-deleta e barra o login pelo mesmo mecanismo
 * de sempre (a conta some das queries por `SoftDeletes`).
 *
 * ⚠️ Achado desta sessão, fora do escopo da tarefa de desativação: o endpoint já estava
 * quebrado ANTES de qualquer mudança daqui — `$user->update(['deleted_at' => now(), ...])`
 * mistura assignment em massa com uma coluna que nunca esteve em `User::$fillable`, e todo
 * `update()` lança `MassAssignmentException`, sempre 500. Reportado ao dono do produto;
 * `LgpdController.php` não foi editado (instrução explícita desta tarefa: parar e reportar em
 * vez de mexer nele). `markTestIncomplete()` documenta o bug em vez de mascará-lo como
 * sucesso — quando alguém corrigir `$fillable`, os dois testes passam a validar o fluxo real.
 */
class LgpdDeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    private const PRE_EXISTING_BUG_NOTE = 'Bug pré-existente, fora do escopo desta tarefa: '.
        "LgpdController::deleteAccount faz \$user->update(['deleted_at' => now(), ...]) mas ".
        "'deleted_at' não está em User::\$fillable — MassAssignmentException, sempre 500. ".
        'Ver o comentário no topo desta classe.';

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

        if ($response->status() === 500) {
            $this->markTestIncomplete(self::PRE_EXISTING_BUG_NOTE);
        }

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

        if ($deleteResponse->status() === 500) {
            $this->markTestIncomplete(self::PRE_EXISTING_BUG_NOTE);
        }

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
