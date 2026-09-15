<?php

namespace Tests\Feature;

use App\Models\Professional;
use App\Models\User;
use App\Services\Professional\SpecialtyCatalogIndex;
use App\Support\Catalog\SpecialtyAliasCatalog;
use App\Support\Catalog\SpecialtyPivotBackfill;
use App\Support\Catalog\VeterinarySpecialtyCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regressão: fechar a escrita no catálogo (`ValidSpecialty`) fechou o cadastro de
 * veterinário inteiro.
 *
 * O formulário do app (`2pets-app/src/constants/professionalOptions.js`, const `SPECIALTIES`)
 * manda slug em inglês — `cardiology`, `dentistry`, `general` — e NENHUM dos 14 resolvia
 * contra `specialties.name`. Resultado medido em produção lógica: 422 em todo cadastro de
 * vet que declarasse especialidade, e o autosave do rascunho junto.
 *
 * Este teste fixa as duas metades da correção: o alias resolve para a MESMA linha do
 * catálogo (não cria conceito novo) e o catálogo continua fechado para conceito inventado.
 */
class SpecialtyAliasWriteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Os 14 valores do formulário. `emergency` é o único sem alvo — não é especialidade,
     * é disponibilidade de atendimento (`ServiceCategory::EMERGENCY` +
     * `professionals.emergency_available`).
     *
     * @var array<string, string>
     */
    private const FORM_SLUG_TO_CATALOG_NAME = [
        'general' => 'Clinica Geral',
        'cardiology' => 'Cardiologia',
        'dermatology' => 'Dermatologia',
        'orthopedics' => 'Ortopedia',
        'ophthalmology' => 'Oftalmologia',
        'dentistry' => 'Odontologia Veterinaria',
        'neurology' => 'Neurologia',
        'oncology' => 'Oncologia',
        'surgery' => 'Cirurgia Geral',
        'anesthesiology' => 'Anestesiologia',
        'exotic' => 'Medicina de Animais Silvestres/Exoticos',
        'nutrition' => 'Nutricao Animal',
        'reproduction' => 'Reproducao Animal',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SpecialtySeeder::class);
    }

    private function catalog(): SpecialtyCatalogIndex
    {
        return app(SpecialtyCatalogIndex::class);
    }

    /**
     * @param  list<string>  $specialties
     */
    private function createProfessional(array $specialties): Professional
    {
        $user = User::factory()->professional()->create([
            'profile_completed' => true,
            'registration_status' => 'approved',
            'is_suspended' => false,
        ]);

        return Professional::factory()->create([
            'user_id' => $user->id,
            'professional_type' => 'vet',
            'specialties' => $specialties,
        ]);
    }

    /**
     * @return list<string>
     */
    private function linkedNames(Professional $professional): array
    {
        return $professional->catalogSpecialties()->pluck('name')->sort()->values()->all();
    }

    private function authenticatedVet(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = $this->createProfessional([])->user;
        $user->assignRole('vet_freelancer');

        Sanctum::actingAs($user);

        return $user;
    }

    // ---------------------------------------------------------------
    // O mapa de aliases não pode divergir do catálogo
    // ---------------------------------------------------------------

    /**
     * Alias cujo alvo não existe no catálogo não resolve nada — e o sintoma seria de novo o
     * 422 no cadastro, sem nenhum erro que aponte para a causa.
     */
    public function test_every_alias_points_to_a_row_that_exists_in_the_catalog(): void
    {
        $catalogNames = array_column(VeterinarySpecialtyCatalog::all(), 'name');

        foreach (SpecialtyAliasCatalog::canonicalNameByAlias() as $alias => $canonicalName) {
            $this->assertContains($canonicalName, $catalogNames, "Alias {$alias} aponta para linha inexistente.");
        }
    }

    public function test_no_misfiled_alias_is_also_mapped_to_a_specialty(): void
    {
        $mapped = array_keys(SpecialtyAliasCatalog::canonicalNameByAlias());

        foreach (SpecialtyAliasCatalog::misfiledAliases() as $misfiled) {
            $this->assertNotContains($misfiled, $mapped, "{$misfiled} não pode ser alias e descarte ao mesmo tempo.");
        }
    }

    // ---------------------------------------------------------------
    // Resolução dos 14 valores do formulário
    // ---------------------------------------------------------------

    public function test_the_form_slugs_resolve_to_the_expected_catalog_rows(): void
    {
        foreach (self::FORM_SLUG_TO_CATALOG_NAME as $slug => $expectedName) {
            $this->assertTrue($this->catalog()->isInCatalog($slug), "{$slug} deveria estar no catálogo.");
            $this->assertSame([$expectedName], $this->catalog()->canonicalize([$slug]));
        }
    }

    /**
     * Emergência não vira especialidade nova por causa do alias — o dado verdadeiro já mora
     * em `emergency_available`/`ServiceCategory::EMERGENCY`.
     */
    public function test_emergency_is_not_in_the_catalog_and_is_flagged_as_misfiled(): void
    {
        $this->assertFalse($this->catalog()->isInCatalog('emergency'));
        $this->assertTrue($this->catalog()->isMisfiled('emergency'));
    }

    public function test_a_real_specialty_is_never_flagged_as_misfiled(): void
    {
        $this->assertFalse($this->catalog()->isMisfiled('Cardiologia'));
        $this->assertFalse($this->catalog()->isMisfiled('cardiology'));
    }

    /**
     * O catálogo de escrita não pode ser mais estrito que o vocabulário de busca: o `value`
     * que `GET /public/categories` publica é o que o front reenvia como filtro e, um dia,
     * como declaração.
     */
    public function test_the_canonical_search_forms_still_resolve(): void
    {
        foreach (['diagnostico por imagem', 'Diagnóstico por Imagem', 'clinica_geral', 'nefrologia urologia'] as $value) {
            $this->assertTrue($this->catalog()->isInCatalog($value), "{$value} deveria resolver.");
        }
    }

    // ---------------------------------------------------------------
    // Caminho HTTP estrito: PUT /api/profile
    // ---------------------------------------------------------------

    public function test_the_profile_accepts_the_english_form_slugs_and_stores_the_catalog_spelling(): void
    {
        $user = $this->authenticatedVet();

        $this->putJson('/api/profile', ['professional' => ['specialties' => ['cardiology', 'dentistry', 'general']]])
            ->assertOk();

        $professional = $user->professional()->first();

        $this->assertSame(['Cardiologia', 'Odontologia Veterinaria', 'Clinica Geral'], $professional->specialties);
        $this->assertSame(['Cardiologia', 'Clinica Geral', 'Odontologia Veterinaria'], $this->linkedNames($professional));
    }

    public function test_the_profile_accepts_the_whole_form_list_at_once(): void
    {
        $user = $this->authenticatedVet();

        $submitted = [...array_keys(self::FORM_SLUG_TO_CATALOG_NAME), ...SpecialtyAliasCatalog::misfiledAliases()];

        $this->putJson('/api/profile', ['professional' => ['specialties' => $submitted]])->assertOk();

        $this->assertCount(
            count(self::FORM_SLUG_TO_CATALOG_NAME),
            $this->linkedNames($user->professional()->first())
        );
    }

    /**
     * Descartado, não gravado: `emergency` não pode virar rótulo em `professionals.specialties`,
     * onde a busca e três API Resources leem.
     */
    public function test_emergency_is_discarded_instead_of_blocking_the_request(): void
    {
        $user = $this->authenticatedVet();

        $this->putJson('/api/profile', ['professional' => ['specialties' => ['emergency', 'cardiology']]])
            ->assertOk();

        $professional = $user->professional()->first();

        $this->assertSame(['Cardiologia'], $professional->specialties);
        $this->assertSame(['Cardiologia'], $this->linkedNames($professional));
    }

    /**
     * O alias não é uma porta dos fundos: conceito inventado continua sendo 422 no caminho
     * onde a recusa tem consequência útil.
     */
    public function test_a_concept_outside_the_catalog_is_still_rejected(): void
    {
        $this->authenticatedVet();

        $this->putJson('/api/profile', ['professional' => ['specialties' => ['Cardiologia Pediatrica Inventada']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('professional.specialties.0');
    }

    // ---------------------------------------------------------------
    // Rascunho: aceita e guarda o que vier
    // ---------------------------------------------------------------

    public function test_the_draft_accepts_a_value_outside_the_catalog_without_422(): void
    {
        $user = User::factory()->create(['user_type' => 'vet']);
        Sanctum::actingAs($user);

        $this->postJson('/api/register/draft/professional', [
            'specialties' => ['cardiology', 'Especialidade Que Nao Existe'],
        ])->assertOk();

        $professional = $user->professional()->first();

        $this->assertSame(['Cardiologia', 'Especialidade Que Nao Existe'], $professional->specialties);
        $this->assertSame(['Cardiologia'], $this->linkedNames($professional));
    }

    public function test_the_draft_still_discards_the_misfiled_value(): void
    {
        $user = User::factory()->create(['user_type' => 'vet']);
        Sanctum::actingAs($user);

        $this->postJson('/api/register/draft/professional', ['specialties' => ['emergency', 'general']])
            ->assertOk();

        $this->assertSame(['Clinica Geral'], $user->professional()->first()->specialties);
    }

    // ---------------------------------------------------------------
    // Backfill (escrita em lote: seeder, migration, query builder)
    // ---------------------------------------------------------------

    /**
     * A tradução precisa existir também em SQL: escrita em lote não dispara o observer, e
     * uma linha gravada com o slug do formulário viraria rótulo órfão — coluna preenchida,
     * pivô vazia, profissional invisível para o filtro de especialidade.
     */
    public function test_the_backfill_links_labels_written_with_the_english_slug(): void
    {
        $professional = $this->createProfessional([]);

        DB::table('professionals')
            ->where('id', $professional->id)
            ->update(['specialties' => json_encode(['cardiology', 'general'])]);

        $report = (new SpecialtyPivotBackfill)->run();

        $this->assertSame(['Cardiologia', 'Clinica Geral'], $this->linkedNames($professional->refresh()));
        $this->assertArrayNotHasKey('cardiology', $report['unmapped']);
    }

    /**
     * `emergency` não tem alvo e nunca terá: precisa continuar aparecendo no relatório, para
     * que o dia em que voltar a ser gravado não passe despercebido.
     */
    public function test_the_backfill_reports_emergency_as_unmapped(): void
    {
        $professional = $this->createProfessional([]);

        DB::table('professionals')
            ->where('id', $professional->id)
            ->update(['specialties' => json_encode(['emergency'])]);

        $report = (new SpecialtyPivotBackfill)->run();

        $this->assertArrayHasKey('emergency', $report['unmapped']);
        $this->assertSame([], $this->linkedNames($professional->refresh()));
    }
}
