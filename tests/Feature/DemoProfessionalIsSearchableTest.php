<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A conta de demonstração do profissional (`vet@2pets.com.br`, Dra. Carolina Mendes) tem que
 * APARECER na busca pública.
 *
 * Ela nascia com `profile_completed = false`, `registration_status = 'completed'` e
 * `location = NULL`. `User::scopeVisibleProfessional()` exige `profile_completed = true` E
 * `registration_status = 'approved'`: ela falhava nos dois e ainda não tinha coordenada.
 * Resultado medido antes da correção: `?query=Carolina Mendes` devolvia zero — a conta que o
 * time usa para testar o produto era a única que o produto não mostrava.
 *
 * ⚠️ `'completed'` não é `'approved'`. A diferença é invisível na leitura do seeder e só
 * aparece como "a busca não acha ninguém".
 */
class DemoProfessionalIsSearchableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    private function demoProfessional(): User
    {
        return User::where('email', 'vet@2pets.com.br')->firstOrFail();
    }

    public function test_the_demo_professional_passes_the_public_visibility_scope(): void
    {
        $visible = User::query()->visibleProfessional()->pluck('id');

        $this->assertContains($this->demoProfessional()->id, $visible->all());
    }

    public function test_the_demo_professional_is_geolocated_in_the_beta_region(): void
    {
        $professional = $this->demoProfessional();

        $this->assertNotNull($professional->latitude);
        $this->assertNotNull($professional->longitude);
        $this->assertSame('SP', $professional->state);
    }

    public function test_the_demo_professional_is_found_by_name(): void
    {
        $names = collect($this->getJson('/api/public/search?query=Carolina+Mendes')->assertOk()->json('data'))
            ->pluck('name');

        $this->assertContains('Dra. Carolina Mendes', $names->all());
    }

    /**
     * Não basta existir na lista: a conta precisa ser encontrável pelo que ela DIZ oferecer,
     * senão ela continua sendo um cadastro incoerente que só aparece por acaso de nome.
     */
    public function test_the_demo_professional_is_found_by_its_declared_competence(): void
    {
        $id = $this->demoProfessional()->id;

        $bySpecialty = collect($this->getJson('/api/public/search?specialty=cardiologia')->assertOk()->json('data'))
            ->pluck('id');
        $byType = collect($this->getJson('/api/public/search?professional_type=vet')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertContains($id, $bySpecialty->all());
        $this->assertContains($id, $byType->all());
    }

    public function test_the_demo_professional_declares_the_species_it_serves(): void
    {
        $id = $this->demoProfessional()->id;

        $matched = collect($this->getJson('/api/public/search?species[]=dog&species[]=cat')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertContains($id, $matched->all());
    }
}
