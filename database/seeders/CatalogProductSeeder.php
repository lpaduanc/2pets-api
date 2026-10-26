<?php

namespace Database\Seeders;

use App\Models\CatalogBrand;
use App\Models\CatalogProduct;
use Illuminate\Database\Seeder;

/**
 * Carga do catálogo global (`catalog_products`) a partir de `database/seeders/data/catalog/*.json`.
 *
 * Os JSON trazem só dado factual (nome, marca, peso, EAN, atributos) e descrição própria — ver a
 * migration `create_catalog_products_table`. Reexecutar é seguro: o upsert é por `dedup_key`, e
 * campos que o admin cura na tela (`image_*`, `is_active`, `description` editada) não são
 * sobrescritos em item que já existe.
 */
class CatalogProductSeeder extends Seeder
{
    private const DATA_GLOB = __DIR__.'/data/catalog/*.json';

    public function run(): void
    {
        $created = 0;

        foreach (glob(self::DATA_GLOB) as $file) {
            foreach (json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) as $row) {
                $created += (int) $this->upsert($row);
            }
        }

        $this->command?->info("Catálogo global: {$created} item(ns) novo(s).");
    }

    /** @param  array<string, mixed>  $row */
    private function upsert(array $row): bool
    {
        $brand = CatalogBrand::findOrCreateByName($row['brand']);
        $existing = CatalogProduct::withTrashed()
            ->where('dedup_key', $row['dedup_key'])
            ->when($row['ean'], fn ($q) => $q->orWhere('ean', $row['ean']))
            ->first();

        $facts = [
            'catalog_brand_id' => $brand->id,
            'category' => $row['category'],
            'species' => $row['species'],
            'line_name' => $row['line_name'],
            'variant_label' => $row['variant_label'],
            'net_weight_grams' => $row['net_weight_grams'],
            'attributes' => $row['attributes'],
        ];

        if ($existing) {
            $existing->update($facts);

            return false;
        }

        CatalogProduct::create($facts + [
            'name' => $row['name'],
            'ean' => $row['ean'],
            'dedup_key' => $row['dedup_key'],
            'description' => $row['description'],
        ]);

        return true;
    }
}
