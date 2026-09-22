<?php

use App\Enums\ImmunizationGroup;
use App\Enums\PetSpecies;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migração de dado (não de schema) — contrato spec 13, "Migração de `pet_dewormings`" e
 * critério de aceite "preserva 100% das linhas existentes". `vaccine_catalog` NÃO é dropada
 * nem renomeada (permanece só leitura histórica, ainda referenciada por código legado) — os
 * 11 registros seedados são copiados 1:1 para `immunization_products` (`group = vaccine`,
 * `organization_id = null` = catálogo global), com uma linha de espécie em
 * `immunization_product_species` por produto (o `species` original já era único).
 *
 * `pet_dewormings`/`vaccinations` continuam com seu schema/write-path atual, intocados —
 * nenhuma linha histórica é movida ou apagada por esta migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('vaccine_catalog')) {
            return;
        }

        $legacyEntries = DB::table('vaccine_catalog')->orderBy('id')->get();

        foreach ($legacyEntries as $legacyEntry) {
            $species = PetSpecies::tryFrom((string) $legacyEntry->species);
            if ($species === null) {
                continue;
            }

            $productId = DB::table('immunization_products')->insertGetId([
                'organization_id' => null,
                'name' => $legacyEntry->name,
                'group' => ImmunizationGroup::VACCINE->value,
                'manufacturer' => null,
                'description' => $legacyEntry->description,
                'legally_required' => (bool) $legacyEntry->required,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('immunization_product_species')->insert([
                'immunization_product_id' => $productId,
                'species' => $species->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('immunization_products')
            ->where('group', ImmunizationGroup::VACCINE->value)
            ->whereNull('organization_id')
            ->delete();
    }
};
