<?php

namespace App\Services\Medical\Immunization;

use App\Models\ImmunizationProduct;
use App\Models\ImmunizationProtocol;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Escrita do catálogo (produto + espécies, protocolo + grafo de doses) — contrato
 * docs/gap-simplesvet/specs/13-protocolos-vacinais-spec.md.
 */
final class ImmunizationCatalogService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createProduct(array $data, ?int $organizationId): ImmunizationProduct
    {
        return DB::transaction(function () use ($data, $organizationId): ImmunizationProduct {
            $product = ImmunizationProduct::create([
                'organization_id' => $organizationId,
                'name' => $data['name'],
                'group' => $data['group'],
                'manufacturer' => $data['manufacturer'] ?? null,
                'description' => $data['description'] ?? null,
                'legally_required' => $data['legally_required'] ?? false,
                'active' => $data['active'] ?? true,
            ]);

            $this->syncSpecies($product, $data['species']);

            return $product->fresh('speciesLinks');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProduct(ImmunizationProduct $product, array $data): ImmunizationProduct
    {
        return DB::transaction(function () use ($product, $data): ImmunizationProduct {
            $product->update(array_intersect_key($data, array_flip([
                'name', 'group', 'manufacturer', 'description', 'legally_required', 'active',
            ])));

            if (array_key_exists('species', $data)) {
                $this->syncSpecies($product, $data['species']);
            }

            return $product->fresh('speciesLinks');
        });
    }

    /**
     * @param  list<string>  $species
     */
    private function syncSpecies(ImmunizationProduct $product, array $species): void
    {
        $product->speciesLinks()->delete();
        $product->speciesLinks()->createMany(
            collect($species)->unique()->map(fn (string $value): array => ['species' => $value])->all()
        );
    }

    /**
     * Cria o protocolo e todo o grafo de doses numa única transação. `doses` chega com
     * `dose_number` (chave do grafo dentro do payload) e `depends_on_dose_number` opcional —
     * resolvido para `depends_on_dose_id` real em duas passadas (cria tudo sem o vínculo,
     * depois liga pelos números já persistidos).
     *
     * @param  array<string, mixed>  $data
     */
    public function createProtocol(ImmunizationProduct $product, array $data, ?int $organizationId): ImmunizationProtocol
    {
        return DB::transaction(function () use ($product, $data, $organizationId): ImmunizationProtocol {
            $protocol = ImmunizationProtocol::create([
                'immunization_product_id' => $product->id,
                'organization_id' => $organizationId,
                'name' => $data['name'],
                'application_mode' => $data['application_mode'],
                'total_doses' => $data['total_doses'] ?? null,
                'active' => $data['active'] ?? true,
            ]);

            $doseIdsByNumber = $this->createDoses($protocol, collect($data['doses']));
            $this->linkDependencies($protocol, collect($data['doses']), $doseIdsByNumber);

            return $protocol->fresh('doses');
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $doses
     * @return array<int, int> dose_number => id persistido
     */
    private function createDoses(ImmunizationProtocol $protocol, Collection $doses): array
    {
        $doseIdsByNumber = [];

        foreach ($doses as $doseData) {
            $created = $protocol->doses()->create([
                'dose_number' => $doseData['dose_number'],
                'interval_days' => $doseData['interval_days'] ?? null,
                'anchor' => $doseData['anchor'],
                'transitions_to_product_id' => $doseData['transitions_to_product_id'] ?? null,
                'min_age_days' => $doseData['min_age_days'] ?? null,
                'max_age_days' => $doseData['max_age_days'] ?? null,
                'notes' => $doseData['notes'] ?? null,
            ]);

            $doseIdsByNumber[(int) $doseData['dose_number']] = $created->id;
        }

        return $doseIdsByNumber;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $doses
     * @param  array<int, int>  $doseIdsByNumber
     */
    private function linkDependencies(ImmunizationProtocol $protocol, Collection $doses, array $doseIdsByNumber): void
    {
        foreach ($doses as $doseData) {
            $dependsOnNumber = $doseData['depends_on_dose_number'] ?? null;
            if ($dependsOnNumber === null) {
                continue;
            }

            $protocol->doses()
                ->where('dose_number', $doseData['dose_number'])
                ->update(['depends_on_dose_id' => $doseIdsByNumber[(int) $dependsOnNumber] ?? null]);
        }
    }
}
