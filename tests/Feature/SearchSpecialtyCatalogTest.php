<?php

namespace Tests\Feature;

use App\Models\Professional;
use App\Models\User;
use App\Support\Catalog\VeterinarySpecialtyCatalog;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/public/categories` tem que oferecer TODAS as especialidades do catálogo.
 *
 * A UI da busca tinha 8 especialidades chumbadas no `SearchPage.vue` enquanto a base grava
 * 21: as duas maiores ("Diagnostico por Imagem", 836 profissionais, e "Patologia Clinica",
 * 829) não eram filtráveis por ninguém. É o mesmo furo que a espécie tinha — lista de filtro
 * mantida à mão no frontend perde o passo do catálogo e some com resultado real.
 *
 * O invariante que mais importa aqui: **o `value` publicado tem que ser aceito pelo filtro**.
 * Um catálogo cujo `value` o `?specialty[]=` não resolve é pior que catálogo nenhum — ele
 * oferece opções que devolvem zero.
 */
class SearchSpecialtyCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array<string, string>>
     */
    private function catalogOptions(): array
    {
        return $this->getJson('/api/public/categories')->assertOk()->json('specialties');
    }

    private function createProfessionalWithSpecialty(string $name, string $specialtyName): void
    {
        $user = User::factory()->professional()->create([
            'name' => $name,
            'profile_completed' => true,
            'registration_status' => 'approved',
            'is_suspended' => false,
        ]);

        Professional::factory()->create([
            'user_id' => $user->id,
            'business_name' => null,
            'description' => null,
            'specialties' => [$specialtyName],
            'species_served' => null,
            'professional_type' => 'vet',
        ]);
    }

    public function test_the_endpoint_publishes_one_option_per_catalog_specialty(): void
    {
        $this->assertCount(count(VeterinarySpecialtyCatalog::all()), $this->catalogOptions());
    }

    public function test_every_option_carries_a_value_and_a_label(): void
    {
        foreach ($this->catalogOptions() as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
            $this->assertNotSame('', $option['value']);
            $this->assertNotSame('', $option['label']);
        }
    }

    /**
     * O rótulo vem do catálogo (a mesma fonte que semeia `specialties` e que decide quem pode
     * declarar o quê), nunca de uma lista redigitada no controller.
     */
    public function test_the_labels_come_from_the_catalog(): void
    {
        $labels = array_column($this->catalogOptions(), 'label');

        foreach (VeterinarySpecialtyCatalog::all() as $specialty) {
            $this->assertContains($specialty['label'], $labels);
        }
    }

    public function test_the_two_largest_specialties_of_the_base_are_offered(): void
    {
        $labels = array_column($this->catalogOptions(), 'label');

        $this->assertContains('Diagnóstico por Imagem', $labels);
        $this->assertContains('Patologia Clínica', $labels);
    }

    /**
     * O teste que justifica o endpoint: cada `value` publicado, reenviado como
     * `?specialty[]=`, tem que encontrar quem declarou aquela especialidade.
     */
    public function test_every_published_value_is_accepted_by_the_specialty_filter(): void
    {
        $this->seed(SpecialtySeeder::class);

        foreach (VeterinarySpecialtyCatalog::all() as $index => $specialty) {
            $this->createProfessionalWithSpecialty('Profissional '.$index, $specialty['name']);
        }

        foreach ($this->catalogOptions() as $option) {
            $response = $this->getJson('/api/public/search?'.http_build_query(['specialty' => [$option['value']]]));

            $this->assertGreaterThan(
                0,
                $response->assertOk()->json('meta.total'),
                'O value "'.$option['value'].'" do catalogo nao devolveu resultado nenhum.'
            );
        }
    }

    public function test_the_endpoint_still_publishes_the_other_two_catalogs(): void
    {
        $response = $this->getJson('/api/public/categories')->assertOk();

        $this->assertNotEmpty($response->json('professional_types'));
        $this->assertNotEmpty($response->json('service_categories'));
    }
}
