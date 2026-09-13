<?php

namespace Tests\Feature;

use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regressão do bug mais grave da auditoria de cadastro (2026-09-13):
 * `RegistrationDraftController::updateProfessionalDatabase` fazia HARD DELETE do
 * `Professional` de outro usuário em colisão de CNPJ no autosave de rascunho — disparado
 * automaticamente pelo front, sem transação, sem checar dono, sem confirmação.
 *
 * Corrigido removendo a checagem manual e deixando o índice único parcial do banco
 * (`professionals_cnpj_unique`, `WHERE deleted_at IS NULL`) barrar a escrita — convertida no
 * mesmo contrato de duplicidade usado no resto do cadastro (`DuplicateRegistrationException`).
 */
class RegistrationDraftDuplicateProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_professional_draft_with_someone_elses_cnpj_never_deletes_their_record(): void
    {
        $victim = Professional::factory()->create([
            'professional_type' => 'petshop',
            'cnpj' => '11222333000181',
        ]);

        $attacker = User::factory()->create(['user_type' => 'petshop']);
        Sanctum::actingAs($attacker);

        $response = $this->postJson('/api/register/draft/professional', [
            'business_name' => 'Tentativa de Colisão',
            'cnpj' => '11.222.333/0001-81',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('duplicate.fields', ['cnpj'])
            ->assertJsonPath('duplicate.action', 'login')
            ->assertJsonFragment(['cnpj' => ['Este CNPJ já está cadastrado.']]);

        // O registro da vítima continua existindo (e não foi soft-deletado): `find()` já
        // exclui soft-deleted por padrão, então um resultado não-nulo prova as duas coisas.
        $this->assertNotNull(Professional::find($victim->id));
        $this->assertDatabaseMissing('professionals', ['user_id' => $attacker->id]);
    }

    public function test_saving_professional_draft_with_own_cnpj_again_still_works(): void
    {
        $user = User::factory()->create(['user_type' => 'petshop']);
        Professional::factory()->create([
            'user_id' => $user->id,
            'professional_type' => 'petshop',
            'cnpj' => '11222333000181',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/draft/professional', [
            'business_name' => 'Nome Atualizado',
            'cnpj' => '11.222.333/0001-81',
        ]);

        $response->assertOk()->assertJsonPath('saved_to_database', true);

        $this->assertDatabaseHas('professionals', [
            'user_id' => $user->id,
            'business_name' => 'Nome Atualizado',
        ]);
    }
}
