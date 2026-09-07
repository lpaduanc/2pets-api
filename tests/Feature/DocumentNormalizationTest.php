<?php

namespace Tests\Feature;

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Cpf;
use App\Models\Company;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regra de projeto: CPF e CNPJ são gravados como string limpa (só dígitos) e toda busca
 * normaliza a entrada antes de consultar.
 *
 * O teste cobre as duas camadas que garantem isso — mutator do model (invariante de escrita)
 * e `prepareForValidation()` do Form Request (entrada validada no mesmo formato da coluna).
 */
class DocumentNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_value_objects_strip_every_mask_character(): void
    {
        $this->assertSame('12345678900', Cpf::stripMask('123.456.789-00'));
        $this->assertSame('12345678000190', Cnpj::stripMask('12.345.678/0001-90'));

        $this->assertSame('12345678900', (string) Cpf::tryParse('123.456.789-00'));
        $this->assertSame('12345678000190', (string) Cnpj::tryParse('12.345.678/0001-90'));
    }

    public function test_value_objects_reject_wrong_digit_count(): void
    {
        $this->assertNull(Cpf::tryParse('123.456.789'));
        $this->assertNull(Cnpj::tryParse('12.345.678/0001'));
    }

    public function test_user_stores_cpf_and_cnpj_without_mask(): void
    {
        $user = User::factory()->tutor()->create([
            'cpf' => '123.456.789-00',
            'cnpj' => '12.345.678/0001-90',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'cpf' => '12345678900',
            'cnpj' => '12345678000190',
        ]);
    }

    public function test_professional_stores_cnpj_without_mask(): void
    {
        $user = User::factory()->veterinarian()->create();

        $professional = Professional::create([
            'user_id' => $user->id,
            'professional_type' => 'clinic',
            'business_name' => 'Clínica Teste',
            'cnpj' => '12.345.678/0001-90',
        ]);

        $this->assertDatabaseHas('professionals', [
            'id' => $professional->id,
            'cnpj' => '12345678000190',
        ]);
    }

    public function test_company_stores_cnpj_without_mask(): void
    {
        $user = User::factory()->tutor()->create();

        $company = Company::create([
            'user_id' => $user->id,
            'company_name' => 'Empresa Teste',
            'cnpj' => '12.345.678/0001-90',
        ]);

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'cnpj' => '12345678000190',
        ]);
    }

    public function test_empty_document_is_stored_as_null_not_empty_string(): void
    {
        $user = User::factory()->tutor()->create(['cpf' => '']);

        $this->assertNull($user->fresh()->cpf);
    }

    public function test_profile_update_accepts_masked_cpf_and_persists_it_clean(): void
    {
        $user = User::factory()->tutor()->create(['cpf' => null]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['cpf' => '390.533.447-05'])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'cpf' => '39053344705']);
    }

    /**
     * Sem normalizar antes de validar, a checagem de unicidade compara a máscara contra a
     * coluna limpa, não acha nada, deixa passar — e a gravação morre no índice único (500).
     */
    public function test_masked_cpf_already_taken_is_caught_by_validation(): void
    {
        User::factory()->tutor()->create(['cpf' => '39053344705']);

        $user = User::factory()->tutor()->create(['cpf' => null]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['cpf' => '390.533.447-05'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cpf');
    }

    public function test_cpf_with_wrong_digit_count_is_rejected_by_profile_update(): void
    {
        $user = User::factory()->tutor()->create(['cpf' => null]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['cpf' => '390.533.44'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cpf');
    }
}
