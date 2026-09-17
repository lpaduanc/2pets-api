<?php

namespace Tests\Feature;

use App\Models\DietaryRestriction;
use App\Models\FoodAllergy;
use App\Models\FoodBrand;
use App\Models\Pathology;
use App\Models\Specialty;
use App\Models\VaccineCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Pathologies
    // ---------------------------------------------------------------

    public function test_pathologies_endpoint_returns_data(): void
    {
        Pathology::create(['name' => 'Diabetes', 'species' => 'dog', 'category' => 'endocrine']);
        Pathology::create(['name' => 'Epilepsia', 'species' => 'dog', 'category' => 'neurological']);
        Pathology::create(['name' => 'Asma Felina', 'species' => 'cat', 'category' => 'respiratory']);

        $response = $this->getJson('/api/public/pathologies');

        $response->assertOk()
            ->assertJsonCount(3);

        $data = $response->json();
        // Verify ordered by name
        $names = array_column($data, 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertEquals($sorted, $names);
    }

    /**
     * `Pathology.is_chronic` (contrato
     * docs/atendimento-veterinario/05-contrato-consulta-sem-digitacao.md §8) — o endpoint não
     * usa Resource, então o campo precisa aparecer no JSON assim que `$fillable`/`$casts`
     * cobrirem a coluna.
     */
    public function test_pathologies_endpoint_exposes_is_chronic(): void
    {
        Pathology::create(['name' => 'Diabetes', 'species' => 'dog', 'category' => 'metabolic', 'is_chronic' => true]);
        Pathology::create(['name' => 'Gastroenterite Aguda', 'species' => null, 'category' => 'gastrointestinal', 'is_chronic' => false]);

        $data = $this->getJson('/api/public/pathologies')->assertOk()->json();

        $byName = collect($data)->keyBy('name');
        $this->assertTrue($byName['Diabetes']['is_chronic']);
        $this->assertFalse($byName['Gastroenterite Aguda']['is_chronic']);
    }

    public function test_pathologies_filters_by_species(): void
    {
        Pathology::create(['name' => 'Diabetes Canina', 'species' => 'dog', 'category' => 'endocrine']);
        Pathology::create(['name' => 'Cardiopatia Canina', 'species' => 'dog', 'category' => 'cardiovascular']);
        Pathology::create(['name' => 'Asma Felina', 'species' => 'cat', 'category' => 'respiratory']);

        $response = $this->getJson('/api/public/pathologies?species=dog');

        $response->assertOk()
            ->assertJsonCount(2);

        $data = $response->json();
        foreach ($data as $pathology) {
            $this->assertEquals('dog', $pathology['species']);
        }
    }

    // ---------------------------------------------------------------
    // Vaccine catalog
    // ---------------------------------------------------------------

    public function test_vaccine_catalog_returns_data(): void
    {
        VaccineCatalog::create([
            'name' => 'V8',
            'species' => 'dog',
            'doses_required' => 3,
            'interval_days' => 21,
            'booster_interval_days' => 365,
            'required' => true,
        ]);
        VaccineCatalog::create([
            'name' => 'Antirrabica',
            'species' => 'dog',
            'doses_required' => 1,
            'interval_days' => 0,
            'booster_interval_days' => 365,
            'required' => true,
        ]);
        VaccineCatalog::create([
            'name' => 'Triplice Felina',
            'species' => 'cat',
            'doses_required' => 3,
            'interval_days' => 21,
            'booster_interval_days' => 365,
            'required' => true,
        ]);

        $response = $this->getJson('/api/public/vaccine-catalog');

        $response->assertOk()
            ->assertJsonCount(3);
    }

    public function test_vaccine_catalog_filters_by_species(): void
    {
        VaccineCatalog::create([
            'name' => 'V8',
            'species' => 'dog',
            'doses_required' => 3,
            'interval_days' => 21,
            'booster_interval_days' => 365,
            'required' => true,
        ]);
        VaccineCatalog::create([
            'name' => 'Triplice Felina',
            'species' => 'cat',
            'doses_required' => 3,
            'interval_days' => 21,
            'booster_interval_days' => 365,
            'required' => true,
        ]);

        $response = $this->getJson('/api/public/vaccine-catalog?species=cat');

        $response->assertOk()
            ->assertJsonCount(1);

        $data = $response->json();
        $this->assertEquals('cat', $data[0]['species']);
        $this->assertEquals('Triplice Felina', $data[0]['name']);
    }

    // ---------------------------------------------------------------
    // Food brands
    // ---------------------------------------------------------------

    public function test_food_brands_returns_data(): void
    {
        FoodBrand::create(['name' => 'Royal Canin', 'type' => 'dry', 'species_target' => 'dog']);
        FoodBrand::create(['name' => 'Whiskas', 'type' => 'wet', 'species_target' => 'cat']);
        FoodBrand::create(['name' => 'Premier', 'type' => 'dry', 'species_target' => 'all']);

        $response = $this->getJson('/api/public/food-brands');

        $response->assertOk()
            ->assertJsonCount(3);
    }

    public function test_food_brands_filters_by_type(): void
    {
        FoodBrand::create(['name' => 'Royal Canin', 'type' => 'dry', 'species_target' => 'dog']);
        FoodBrand::create(['name' => 'Whiskas', 'type' => 'wet', 'species_target' => 'cat']);
        FoodBrand::create(['name' => 'Premier', 'type' => 'dry', 'species_target' => 'all']);

        $response = $this->getJson('/api/public/food-brands?type=dry');

        $response->assertOk()
            ->assertJsonCount(2);

        $data = $response->json();
        foreach ($data as $brand) {
            $this->assertEquals('dry', $brand['type']);
        }
    }

    // ---------------------------------------------------------------
    // Specialties
    // ---------------------------------------------------------------

    public function test_specialties_returns_data(): void
    {
        Specialty::create(['name' => 'Cardiologia', 'description' => 'Especialidade cardiovascular']);
        Specialty::create(['name' => 'Dermatologia', 'description' => 'Especialidade de pele']);
        Specialty::create(['name' => 'Oftalmologia', 'description' => 'Especialidade ocular']);

        $response = $this->getJson('/api/public/specialties');

        $response->assertOk()
            ->assertJsonCount(3);

        $data = $response->json();
        $names = array_column($data, 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertEquals($sorted, $names);
    }

    // ---------------------------------------------------------------
    // Food allergies
    // ---------------------------------------------------------------

    public function test_food_allergies_returns_data(): void
    {
        FoodAllergy::create(['name' => 'Frango', 'description' => 'Alergia a proteina de frango']);
        FoodAllergy::create(['name' => 'Gluten', 'description' => 'Intolerancia a gluten']);

        $response = $this->getJson('/api/public/food-allergies');

        $response->assertOk()
            ->assertJsonCount(2);
    }

    // ---------------------------------------------------------------
    // Dietary restrictions
    // ---------------------------------------------------------------

    public function test_dietary_restrictions_returns_data(): void
    {
        DietaryRestriction::create(['name' => 'Baixo sodio', 'description' => 'Restricao de sodio']);
        DietaryRestriction::create(['name' => 'Hipoalergenico', 'description' => 'Dieta hipoalergenica']);
        DietaryRestriction::create(['name' => 'Renal', 'description' => 'Dieta renal']);

        $response = $this->getJson('/api/public/dietary-restrictions');

        $response->assertOk()
            ->assertJsonCount(3);
    }
}
