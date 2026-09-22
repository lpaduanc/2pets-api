<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contrato spec 13, critério de aceite: "Migração de `pet_dewormings` preserva 100% das
 * linhas existentes, legíveis no histórico do pet" + "`vaccine_catalog` copiado 1:1 para
 * `immunization_products`".
 *
 * `pet_dewormings`/`vaccine_catalog` NÃO são alteradas por esta entrega (decisão de
 * implementação registrada no contrato de API) — os dois testes abaixo comprovam as duas
 * metades da garantia: (1) o dado legado de vermifugação continua 100% intacto e legível;
 * (2) a cópia de catálogo funciona quando a migration roda sobre `vaccine_catalog` populada
 * (invocada diretamente aqui porque, no ambiente de teste, a migration de dado roda ANTES do
 * seeder que popula `vaccine_catalog` em produção/dev).
 */
class DewormingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_pet_dewormings_remain_fully_readable_after_the_new_schema(): void
    {
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        PetDeworming::create([
            'pet_id' => $pet->id,
            'product_name' => 'Vermífugo legado',
            'applied_date' => '2025-01-10',
            'next_date' => '2025-04-10',
        ]);

        Sanctum::actingAs($tutor);

        $response = $this->getJson("/api/pets/{$pet->id}/health/dewormings");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Vermífugo legado', $response->json('data.0.product_name'));
    }

    public function test_seed_migration_copies_legacy_vaccine_catalog_rows_into_immunization_products(): void
    {
        DB::table('vaccine_catalog')->insert([
            [
                'name' => 'Antirrábica', 'species' => 'dog', 'doses_required' => 1,
                'interval_days' => null, 'booster_interval_days' => 365,
                'description' => 'Obrigatória', 'required' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'name' => 'V4 Felina', 'species' => 'cat', 'doses_required' => 3,
                'interval_days' => 21, 'booster_interval_days' => 365,
                'description' => null, 'required' => false,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $migration = require database_path(
            'migrations/2026_10_06_100006_seed_immunization_products_from_vaccine_catalog.php'
        );
        $migration->up();

        $this->assertDatabaseCount('immunization_products', 2);
        $this->assertDatabaseHas('immunization_products', [
            'name' => 'Antirrábica', 'group' => 'vaccine', 'organization_id' => null, 'legally_required' => true,
        ]);
        $this->assertDatabaseCount('immunization_product_species', 2);
        $this->assertDatabaseHas('immunization_product_species', ['species' => 'cat']);

        // `vaccine_catalog` continua intacta — não é dropada nem esvaziada.
        $this->assertDatabaseCount('vaccine_catalog', 2);
    }
}
