<?php

namespace App\Services\Stock;

use App\Enums\InventoryCategory;
use App\Models\Inventory;
use Illuminate\Support\Collection;

/**
 * Um grupo = um `Product` de destino. Existe porque a spec de consolidação (item 5/6) exige
 * deduplicar `inventories` do MESMO item antes de criar produto: duas linhas com o mesmo nome
 * cadastradas pelo mesmo profissional (às vezes com `organization_id` divergente por causa do
 * bug de escopo relatado em `InventoryController`) são o MESMO produto com lotes diferentes,
 * não dois produtos.
 *
 * @property-read Collection<int, Inventory> $rows
 */
final class LegacyInventoryGroup
{
    /**
     * @param  Collection<int, Inventory>  $rows  ordenadas por id — a primeira é a "primária"
     *                                            (dona do `legacy_inventory_id` do produto).
     */
    public function __construct(
        public readonly int $professionalId,
        public readonly ?int $organizationId,
        public readonly string $name,
        public readonly InventoryCategory $category,
        public readonly Collection $rows,
    ) {}

    public function primaryRow(): Inventory
    {
        return $this->rows->first();
    }
}
