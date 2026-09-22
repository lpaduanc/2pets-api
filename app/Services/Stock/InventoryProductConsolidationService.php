<?php

namespace App\Services\Stock;

use App\Enums\ImmunizationGroup;
use App\Enums\InventoryCategory;
use App\Enums\ProductPurpose;
use App\Models\ImmunizationProduct;
use App\Models\Inventory;
use App\Models\PetDeworming;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Supplier;
use App\Models\Vaccination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migração de dado (não de schema) — consolida `inventories`/`inventory_movements` dentro de
 * `products`/`product_batches`/`stock_movements`. Contrato completo em
 * docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md e
 * docs/gap-simplesvet/contratos/produtos-estoque-consolidado-contrato-api.md.
 *
 * Idempotente: `products.legacy_inventory_id`/`product_batches.legacy_inventory_id` marcam o
 * que já foi migrado — reexecutar só toca grupos ainda não migrados (a linha PRIMÁRIA de cada
 * grupo é o guard). Nunca apaga nem edita `inventories`/`inventory_movements`.
 */
final class InventoryProductConsolidationService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly LegacyInventoryClassifier $classifier,
        private readonly LegacyInventoryGrouper $grouper,
        private readonly LegacyStockLedgerReplay $replay,
    ) {}

    /**
     * @return array{groups_migrated: int, groups_skipped: int, clinical_records_relinked: int}
     */
    public function migrate(): array
    {
        $groups = $this->grouper->group(Inventory::withTrashed()->orderBy('id')->get());
        $inventoryToProduct = [];
        $migrated = 0;
        $skipped = 0;

        foreach ($groups as $group) {
            if ($this->alreadyMigrated($group)) {
                $skipped++;

                continue;
            }

            $this->migrateGroup($group, $inventoryToProduct);
            $migrated++;
        }

        $relinked = $this->backfillClinicalReferences($inventoryToProduct);

        return ['groups_migrated' => $migrated, 'groups_skipped' => $skipped, 'clinical_records_relinked' => $relinked];
    }

    private function alreadyMigrated(LegacyInventoryGroup $group): bool
    {
        return Product::withTrashed()->where('legacy_inventory_id', $group->primaryRow()->id)->exists();
    }

    /**
     * @param  array<int, array{product_id: int, product_batch_id: ?int}>  $inventoryToProduct
     */
    private function migrateGroup(LegacyInventoryGroup $group, array &$inventoryToProduct): void
    {
        DB::transaction(function () use ($group, &$inventoryToProduct): void {
            if ($group->category === InventoryCategory::EQUIPMENT) {
                $this->migrateEquipmentGroup($group, $inventoryToProduct);

                return;
            }

            $this->migrateStockedGroup($group, $inventoryToProduct);
        });
    }

    /**
     * Item 1a da spec: patrimônio, não estoque consumível — contagem única, sem livro de
     * movimento, fora da lista de preços e da análise de giro.
     *
     * @param  array<int, array{product_id: int, product_batch_id: ?int}>  $inventoryToProduct
     */
    private function migrateEquipmentGroup(LegacyInventoryGroup $group, array &$inventoryToProduct): void
    {
        $product = $this->createProduct($group, controlsStock: false, showInPriceList: false);
        $product->forceFill(['stock_quantity' => $group->rows->sum('quantity')])->saveQuietly();

        foreach ($group->rows as $row) {
            $inventoryToProduct[$row->id] = ['product_id' => $product->id, 'product_batch_id' => null];
        }
    }

    /**
     * @param  array<int, array{product_id: int, product_batch_id: ?int}>  $inventoryToProduct
     */
    private function migrateStockedGroup(LegacyInventoryGroup $group, array &$inventoryToProduct): void
    {
        $immunizationProductId = $this->resolveImmunizationProductId($group);
        $trackBatches = $immunizationProductId !== null || $group->rows->contains(fn (Inventory $r): bool => $r->expiry_date !== null);

        $product = $this->createProduct($group, controlsStock: true, showInPriceList: true, immunizationProductId: $immunizationProductId);
        $product->forceFill(['track_batches' => $trackBatches])->saveQuietly();

        foreach ($group->rows as $row) {
            $batchCode = $trackBatches ? "LEGACY-INV-{$row->id}" : null;
            $this->replay->replay($product, $row, $batchCode);
            $batchId = $batchCode === null ? null : $this->markBatchAsLegacy($product, $batchCode, $row->id);
            $inventoryToProduct[$row->id] = ['product_id' => $product->id, 'product_batch_id' => $batchId];
        }
    }

    private function markBatchAsLegacy(Product $product, string $batchCode, int $inventoryId): ?int
    {
        $batch = $product->batches()->where('batch_code', $batchCode)->first();
        $batch?->forceFill(['legacy_inventory_id' => $inventoryId])->saveQuietly();

        return $batch?->id;
    }

    private function createProduct(
        LegacyInventoryGroup $group,
        bool $controlsStock,
        bool $showInPriceList,
        ?int $immunizationProductId = null,
    ): Product {
        $primary = $group->primaryRow();
        $purpose = $immunizationProductId !== null ? ProductPurpose::CONSUMABLE : $this->classifier->purposeFor($group->category);

        return Product::create([
            'professional_id' => $group->professionalId,
            'organization_id' => $group->organizationId,
            'category_id' => null,
            'product_group_id' => $this->resolveGroupId($group),
            'last_supplier_id' => $this->resolveSupplierId($group),
            'immunization_product_id' => $immunizationProductId,
            'legacy_inventory_id' => $primary->id,
            'name' => $group->name,
            'sku' => 'LEGACY-INV-'.$primary->id,
            'unit_of_sale' => $this->classifier->unitOfSaleFor($primary->unit),
            'purpose' => $purpose,
            'price' => $primary->selling_price ?? 0,
            'average_cost' => $primary->cost_price ?? 0,
            'last_cost' => $primary->cost_price ?? 0,
            'controls_stock' => $controlsStock,
            'track_inventory' => $controlsStock,
            'show_in_price_list' => $showInPriceList && (float) ($primary->selling_price ?? 0) > 0,
            'min_stock' => $group->rows->sum('min_quantity'),
            'is_active' => $group->rows->contains(fn (Inventory $r): bool => $r->deleted_at === null),
        ]);
    }

    private function resolveGroupId(LegacyInventoryGroup $group): int
    {
        $name = $this->classifier->groupNameFor($group->category);
        $searchKey = $group->organizationId !== null
            ? ['organization_id' => $group->organizationId, 'name' => $name]
            : ['professional_id' => $group->professionalId, 'organization_id' => null, 'name' => $name];

        return ProductGroup::query()->firstOrCreate($searchKey, [
            'professional_id' => $group->professionalId,
            'organization_id' => $group->organizationId,
            'name' => $name,
            'active' => true,
        ])->id;
    }

    /** Último fornecedor informado no grupo vence — mesma semântica de `last_supplier_id`. */
    private function resolveSupplierId(LegacyInventoryGroup $group): ?int
    {
        $supplierId = null;

        foreach ($group->rows as $row) {
            if (trim((string) $row->supplier) !== '') {
                $supplierId = $this->findOrCreateSupplier($group, trim($row->supplier));
            }
        }

        return $supplierId;
    }

    private function findOrCreateSupplier(LegacyInventoryGroup $group, string $name): int
    {
        $existing = Supplier::query()
            ->when(
                $group->organizationId !== null,
                fn ($q) => $q->where('organization_id', $group->organizationId),
                fn ($q) => $q->whereNull('organization_id')->where('professional_id', $group->professionalId),
            )
            ->whereRaw('lower(legal_name) = ?', [Str::lower($name)])
            ->first();

        if ($existing !== null) {
            return $existing->id;
        }

        return Supplier::create([
            'organization_id' => $group->organizationId,
            'professional_id' => $group->professionalId,
            'legal_name' => $name,
            'notes' => 'Fornecedor criado automaticamente na migração de inventories → products (nome livre, sem CNPJ).',
        ])->id;
    }

    /**
     * Só vacina tem catálogo clínico seedado hoje (`immunization_products`, todos `group=vaccine`
     * — ver `2026_10_06_100006_seed_immunization_products_from_vaccine_catalog`). Casamento
     * exato de nome, escopado ao catálogo global + o da própria organização; ambíguo (zero ou
     * mais de um resultado) fica sem vínculo — a spec proíbe adivinhação.
     */
    private function resolveImmunizationProductId(LegacyInventoryGroup $group): ?int
    {
        if ($group->category !== InventoryCategory::VACCINE) {
            return null;
        }

        $matches = ImmunizationProduct::query()
            ->where('group', ImmunizationGroup::VACCINE->value)
            ->visibleTo($group->organizationId)
            ->whereRaw('lower(name) = ?', [Str::lower($group->name)])
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first()->id : null;
    }

    /**
     * @param  array<int, array{product_id: int, product_batch_id: ?int}>  $inventoryToProduct
     */
    private function backfillClinicalReferences(array $inventoryToProduct): int
    {
        $relinked = 0;

        foreach ($inventoryToProduct as $inventoryId => $target) {
            $relinked += Vaccination::withTrashed()->where('inventory_id', $inventoryId)->whereNull('product_id')->update([
                'product_id' => $target['product_id'],
                'product_batch_id' => $target['product_batch_id'],
            ]);
            $relinked += PetDeworming::withTrashed()->where('inventory_id', $inventoryId)->whereNull('product_id')->update([
                'product_id' => $target['product_id'],
                'product_batch_id' => $target['product_batch_id'],
            ]);
        }

        return $relinked;
    }
}
