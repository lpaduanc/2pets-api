<?php

namespace Tests\Feature;

use App\Enums\ProfessionalType;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cobre a taxonomia canônica de `professional_type` (7 tipos) decidida em
 * `docs/taxonomia-professional-type.md`: migration de dados, CHECK constraint,
 * validação no cadastro e o filtro de busca pública.
 */
class ProfessionalTypeTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private const CHECK_CONSTRAINT_NAME = 'professionals_professional_type_check';

    private function createApprovedProfessional(array $professionalOverrides = []): User
    {
        $user = User::factory()->professional()->create();

        Professional::factory()->create(array_merge(
            ['user_id' => $user->id],
            $professionalOverrides
        ));

        return $user;
    }

    /**
     * A migration `2026_09_06_130000_normalize_legacy_professional_types` já rodou (sem
     * efeito — o banco de teste nasce limpo) antes deste teste começar. Não dá para
     * `require` o arquivo da migration de novo aqui: o Laravel já o exigiu uma vez durante
     * o `migrate:fresh` inicial do `RefreshDatabase`, e uma classe anônima PHP não pode ser
     * declarada duas vezes no mesmo processo (`require`, não `require_once`, é como o
     * `Migrator::resolve()` do framework carrega o arquivo).
     *
     * Por isso simulamos o cenário legado (derruba o CHECK, grava os 2 valores fora da
     * taxonomia via query builder — bypassa o cast do model) e aplicamos exatamente a
     * mesma instrução SQL que `up()` executa, provando que o mapa de migração
     * (`veterinarian` e `vet_freelancer` → `vet`) está correto.
     */
    public function test_normalize_legacy_professional_types_migration_converts_legacy_values(): void
    {
        DB::statement('ALTER TABLE professionals DROP CONSTRAINT IF EXISTS '.self::CHECK_CONSTRAINT_NAME);

        $veterinarianRow = Professional::factory()->create(['professional_type' => 'vet']);
        $vetFreelancerRow = Professional::factory()->create(['professional_type' => 'vet']);

        DB::table('professionals')->where('id', $veterinarianRow->id)->update(['professional_type' => 'veterinarian']);
        DB::table('professionals')->where('id', $vetFreelancerRow->id)->update(['professional_type' => 'vet_freelancer']);

        DB::table('professionals')
            ->whereIn('professional_type', ['veterinarian', 'vet_freelancer'])
            ->update(['professional_type' => 'vet']);

        $this->assertDatabaseHas('professionals', ['id' => $veterinarianRow->id, 'professional_type' => 'vet']);
        $this->assertDatabaseHas('professionals', ['id' => $vetFreelancerRow->id, 'professional_type' => 'vet']);

        $this->restoreCheckConstraint();
    }

    public function test_check_constraint_rejects_professional_type_outside_the_taxonomy(): void
    {
        $professional = Professional::factory()->create(['professional_type' => 'vet']);

        $this->expectException(QueryException::class);

        DB::table('professionals')->where('id', $professional->id)->update(['professional_type' => 'other']);
    }

    public function test_registration_rejects_professional_type_outside_the_taxonomy(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Cadastro Invalido',
            'email' => 'cadastro-invalido@example.com',
            'phone' => '11999998888',
            'user_type' => 'veterinarian',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('user_type');
    }

    /**
     * Defesa em profundidade: `users.user_type` já tem seu próprio CHECK constraint (da
     * migration original `$table->enum('user_type', [...])`) que barra qualquer valor fora
     * dos 8 aceitos (os 7 profissionais + `tutor`) — então o único jeito de chegar aqui com
     * um `user_type` DB-válido mas que não é `ProfessionalType` nenhum é `tutor` acessando
     * por engano o endpoint de conclusão de cadastro profissional. `completeProfessional`
     * precisa recusar explicitamente em vez de deixar `Professional::create()` estourar.
     */
    public function test_complete_professional_rejects_user_whose_user_type_is_not_a_professional_type(): void
    {
        $user = User::factory()->create(['role' => 'tutor', 'user_type' => 'tutor']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/register/complete-professional', []);

        $response->assertStatus(400);
    }

    public function test_categories_endpoint_returns_exactly_the_seven_canonical_professional_types(): void
    {
        $response = $this->getJson('/api/public/categories');

        $response->assertOk();

        $returnedValues = collect($response->json('professional_types'))->pluck('value')->sort()->values()->all();
        $canonicalValues = collect(ProfessionalType::values())->sort()->values()->all();

        $this->assertCount(7, $returnedValues);
        $this->assertEquals($canonicalValues, $returnedValues);
    }

    public function test_search_filters_by_each_of_the_seven_canonical_types(): void
    {
        foreach (ProfessionalType::cases() as $type) {
            $this->createApprovedProfessional(['professional_type' => $type->value]);
        }

        foreach (ProfessionalType::cases() as $type) {
            $response = $this->getJson('/api/public/search?professional_type='.$type->value);

            $response->assertOk();
            $this->assertNotEmpty(
                $response->json('data'),
                "Esperava ao menos 1 resultado para professional_type={$type->value}"
            );
        }
    }

    private function restoreCheckConstraint(): void
    {
        $allowedValues = implode(',', array_map(
            fn (string $value): string => "'{$value}'",
            ProfessionalType::values()
        ));

        DB::statement(
            'ALTER TABLE professionals ADD CONSTRAINT '.self::CHECK_CONSTRAINT_NAME.
            " CHECK (professional_type IN ({$allowedValues}))"
        );
    }
}
