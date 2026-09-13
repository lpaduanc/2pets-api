<?php

namespace Tests\Feature;

use App\Enums\ProfessionalType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regressão: `users.user_type` nasceu como `$table->enum(...)` com 8 valores, que no Postgres
 * vira CHECK constraint. `company` (lead B2B) nunca entrou nessa lista — a migration que
 * deveria adicioná-lo foi escrita como no-op, e a que converteu a coluna para VARCHAR usou
 * `->change()`, que não derruba CHECK preexistente.
 *
 * Efeito: `POST /register` com `user_type=company` estourava QueryException 23514, e o fluxo
 * inteiro de empresa parceira (`completeCompany`, `CompleteProfileCompany.vue`) era inalcançável.
 */
class CompanyRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['tutor', 'vet_freelancer', 'clinic_owner', 'petshop_owner'] as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }
    }

    public function test_company_lead_can_register(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Rede Pet Benefícios',
            'email' => 'contato@redepet.com.br',
            'phone' => '11999998888',
            'user_type' => 'company',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'additional_data' => [
                'cnpj' => '11222333000181',
                'employee_count' => '50-200',
                'message' => 'Queremos oferecer benefício pet aos colaboradores.',
            ],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'contato@redepet.com.br',
            'user_type' => 'company',
            'registration_status' => 'pending',
        ]);
    }

    /**
     * A constraint tem que continuar existindo — derrubá-la "resolveria" o bug deixando
     * `user_type` aceitar qualquer string, que é justamente a causa-raiz documentada em
     * `docs/taxonomia-professional-type.md`.
     */
    public function test_user_type_check_constraint_still_rejects_unknown_values(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint só existe no PostgreSQL.');
        }

        $user = User::factory()->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('users')->where('id', $user->id)->update(['user_type' => 'nao_existe']);
    }

    public function test_every_canonical_user_type_is_accepted_by_the_constraint(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint só existe no PostgreSQL.');
        }

        $user = User::factory()->create();

        foreach (['tutor', 'company', ...ProfessionalType::values()] as $userType) {
            DB::table('users')->where('id', $user->id)->update(['user_type' => $userType]);

            $this->assertDatabaseHas('users', ['id' => $user->id, 'user_type' => $userType]);
        }
    }
}
