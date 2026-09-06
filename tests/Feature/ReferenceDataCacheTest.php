<?php

namespace Tests\Feature;

use App\Models\Breed;
use App\Models\Specialty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 9 do plano de otimizacao: dado de referencia (patologias, vacinas, marcas de
 * racao, especialidades, alergias, restricoes alimentares e racas) cacheado 24h por
 * contador de versao — nunca `KEYS`/`SCAN`. Sem a invalidacao, um registro novo
 * ficaria invisivel na API por ate 24h.
 */
class ReferenceDataCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_specialty_invalidates_the_cached_list(): void
    {
        Specialty::create(['name' => 'Cardiologia', 'description' => 'Especialidade cardiovascular']);
        Specialty::create(['name' => 'Dermatologia', 'description' => 'Especialidade de pele']);

        $firstResponse = $this->getJson('/api/public/specialties');
        $firstResponse->assertOk()->assertJsonCount(2);

        Specialty::create(['name' => 'Oftalmologia', 'description' => 'Especialidade ocular']);

        $secondResponse = $this->getJson('/api/public/specialties');
        $secondResponse->assertOk()->assertJsonCount(3);
    }

    public function test_creating_a_breed_invalidates_the_cached_list(): void
    {
        Breed::factory()->dog()->create(['name' => 'Labrador Retriever']);

        $firstResponse = $this->getJson('/api/public/breeds');
        $firstResponse->assertOk()->assertJsonCount(1, 'data');

        Breed::factory()->dog()->create(['name' => 'Poodle']);

        $secondResponse = $this->getJson('/api/public/breeds');
        $secondResponse->assertOk()->assertJsonCount(2, 'data');
    }

    /**
     * Duas tabelas de referencia diferentes tem contadores de versao independentes —
     * criar uma raca nao pode invalidar (nem, ao contrario, deixar de invalidar) o
     * cache de especialidades.
     */
    public function test_reference_data_cache_is_isolated_per_table(): void
    {
        Specialty::create(['name' => 'Cardiologia', 'description' => 'Especialidade cardiovascular']);

        $specialtiesResponse = $this->getJson('/api/public/specialties');
        $specialtiesResponse->assertOk()->assertJsonCount(1);

        Breed::factory()->dog()->create(['name' => 'Labrador Retriever']);

        $specialtiesResponseAfterBreedWrite = $this->getJson('/api/public/specialties');
        $specialtiesResponseAfterBreedWrite->assertOk()->assertJsonCount(1);
    }
}
