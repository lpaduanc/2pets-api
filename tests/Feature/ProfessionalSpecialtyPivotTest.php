<?php

namespace Tests\Feature;

use App\Models\Professional;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Catalog\SpecialtyPivotBackfill;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `professional_specialty`: a tabela pivô com FK que `professionals.specialties` (TEXT com
 * JSON dentro) nunca teve.
 *
 * ── Por que a pivô ────────────────────────────────────────────────────────────────────
 * Normalizar na consulta resolve a LEITURA, mas o caminho de ESCRITA continuava gravando o
 * que o cliente mandasse — e foi assim que a mesma base acumulou `"Clínica Geral"`,
 * `"clinica_geral"` e `"general"` como se fossem conceitos diferentes. Com FK, rótulo
 * inventado deixa de ser representável.
 *
 * ── O que ainda NÃO mudou, de propósito ───────────────────────────────────────────────
 * A coluna `specialties` continua existindo e sendo escrita: a busca e três API Resources
 * ainda a leem. As duas são mantidas iguais por `App\Observers\ProfessionalSpecialtyObserver`.
 * Derrubar a coluna no mesmo passo em que se cria a pivô não deixa caminho de volta.
 */
class ProfessionalSpecialtyPivotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SpecialtySeeder::class);
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

    // ---------------------------------------------------------------
    // Escrita dupla mantida pelo observer
    // ---------------------------------------------------------------

    public function test_creating_a_professional_links_the_declared_specialties(): void
    {
        $professional = $this->createProfessional(['Cardiologia', 'Dermatologia']);

        $this->assertSame(['Cardiologia', 'Dermatologia'], $this->linkedNames($professional));
    }

    public function test_updating_the_column_resyncs_the_pivot(): void
    {
        $professional = $this->createProfessional(['Cardiologia']);

        $professional->update(['specialties' => ['Oncologia', 'Neurologia']]);

        $this->assertSame(['Neurologia', 'Oncologia'], $this->linkedNames($professional));
    }

    /**
     * `sync()` e não `attach()`: especialidade retirada do cadastro tem que SAIR da pivô,
     * senão o profissional continua aparecendo numa busca por algo que não declara mais.
     */
    public function test_removing_a_specialty_detaches_it(): void
    {
        $professional = $this->createProfessional(['Cardiologia', 'Dermatologia']);

        $professional->update(['specialties' => ['Cardiologia']]);

        $this->assertSame(['Cardiologia'], $this->linkedNames($professional));
    }

    public function test_clearing_the_column_detaches_everything(): void
    {
        $professional = $this->createProfessional(['Cardiologia']);

        $professional->update(['specialties' => []]);

        $this->assertSame([], $this->linkedNames($professional));
    }

    /**
     * A coluna guarda taxonomia legada (`clinica_geral`, acento, barra). O vínculo tem que
     * cair na linha certa do catálogo mesmo assim — é o que impede o backfill de perder dado
     * e a escrita de criar um conceito paralelo.
     */
    public function test_legacy_spellings_still_link_to_the_catalog_row(): void
    {
        $professional = $this->createProfessional(['clinica_geral', 'Diagnóstico por Imagem', 'nefrologia urologia']);

        $this->assertSame(
            ['Clinica Geral', 'Diagnostico por Imagem', 'Nefrologia/Urologia'],
            $this->linkedNames($professional)
        );
    }

    public function test_a_label_outside_the_catalog_creates_no_link(): void
    {
        $professional = $this->createProfessional(['Cardiologia Pediatrica Inventada']);

        $this->assertSame([], $this->linkedNames($professional));
    }

    // ---------------------------------------------------------------
    // Integridade referencial
    // ---------------------------------------------------------------

    /**
     * ⚠️ A escrita que viola a restrição roda dentro de `DB::transaction()` PRÓPRIA: sob
     * `RefreshDatabase` a suíte inteira já está numa transação, e uma `QueryException` solta
     * aborta essa transação externa — o teste seguinte encontraria a conexão em estado de
     * erro. Com a transação própria, o Postgres volta ao savepoint e só.
     */
    public function test_the_pivot_rejects_a_specialty_that_does_not_exist(): void
    {
        $professional = $this->createProfessional([]);
        $missingSpecialtyId = (int) Specialty::max('id') + 1;

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($professional, $missingSpecialtyId): void {
            DB::table('professional_specialty')->insert([
                'professional_id' => $professional->id,
                'specialty_id' => $missingSpecialtyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_the_same_pair_cannot_be_linked_twice(): void
    {
        $professional = $this->createProfessional(['Cardiologia']);
        $specialtyId = (int) Specialty::where('name', 'Cardiologia')->value('id');

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($professional, $specialtyId): void {
            DB::table('professional_specialty')->insert([
                'professional_id' => $professional->id,
                'specialty_id' => $specialtyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    // ---------------------------------------------------------------
    // Backfill (o caminho dos seeders, que não disparam evento Eloquent)
    // ---------------------------------------------------------------

    public function test_the_backfill_links_what_bulk_writes_left_behind(): void
    {
        $professional = $this->createProfessional([]);

        // Escrita em massa por query builder: NÃO dispara evento, logo não passa pelo observer.
        DB::table('professionals')
            ->where('id', $professional->id)
            ->update(['specialties' => json_encode(['Cardiologia', 'Oncologia'])]);

        $this->assertSame([], $this->linkedNames($professional->refresh()));

        (new SpecialtyPivotBackfill)->run();

        $this->assertSame(['Cardiologia', 'Oncologia'], $this->linkedNames($professional));
    }

    public function test_the_backfill_reports_labels_it_could_not_map(): void
    {
        $professional = $this->createProfessional([]);

        DB::table('professionals')
            ->where('id', $professional->id)
            ->update(['specialties' => json_encode(['Cardiologia', 'Especialidade Inexistente'])]);

        $report = (new SpecialtyPivotBackfill)->run();

        $this->assertArrayHasKey('Especialidade Inexistente', $report['unmapped']);
        $this->assertSame(1, $report['unmapped']['Especialidade Inexistente']);
        $this->assertArrayNotHasKey('Cardiologia', $report['unmapped']);
    }

    public function test_the_backfill_can_run_twice_without_duplicating(): void
    {
        $professional = $this->createProfessional([]);

        DB::table('professionals')
            ->where('id', $professional->id)
            ->update(['specialties' => json_encode(['Cardiologia'])]);

        (new SpecialtyPivotBackfill)->run();
        (new SpecialtyPivotBackfill)->run();

        $this->assertSame(1, DB::table('professional_specialty')->where('professional_id', $professional->id)->count());
    }

    // ---------------------------------------------------------------
    // Caminho de escrita HTTP: catálogo fechado
    // ---------------------------------------------------------------

    private function authenticatedVet(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $professional = $this->createProfessional(['Cardiologia']);
        $user = $professional->user;
        $user->assignRole('vet_freelancer');

        return $user;
    }

    public function test_a_specialty_outside_the_catalog_is_rejected_with_422(): void
    {
        Sanctum::actingAs($this->authenticatedVet());

        $this->putJson('/api/profile', ['professional' => ['specialties' => ['Cardiologia Pediatrica Inventada']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('professional.specialties.0');
    }

    /**
     * Acento, caixa, `_` e `/` são diferenças de APRESENTAÇÃO, não de conceito: recusá-las
     * transformaria compatibilidade com o cliente atual em erro do usuário.
     */
    public function test_legacy_spellings_are_accepted_and_stored_canonically(): void
    {
        $user = $this->authenticatedVet();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['professional' => ['specialties' => ['clinica_geral', 'Diagnóstico por Imagem']]])
            ->assertOk();

        $professional = $user->professional()->first();

        $this->assertSame(['Clinica Geral', 'Diagnostico por Imagem'], $professional->specialties);
        $this->assertSame(['Clinica Geral', 'Diagnostico por Imagem'], $this->linkedNames($professional));
    }

    public function test_a_valid_update_keeps_column_and_pivot_in_agreement(): void
    {
        $user = $this->authenticatedVet();
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['professional' => ['specialties' => ['Oncologia']]])
            ->assertOk();

        $professional = $user->professional()->first();

        $this->assertSame(['Oncologia'], $professional->specialties);
        $this->assertSame(['Oncologia'], $this->linkedNames($professional));
    }
}
