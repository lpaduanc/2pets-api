<?php

namespace App\Services\Stock;

use App\Models\Inventory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Agrupa `inventories` por dono real + nome normalizado — item 5/6 da spec de consolidação.
 * `organization_id` NÃO entra na chave de agrupamento de propósito: é exatamente a coluna que
 * `InventoryController` deixava de preencher (bug de escopo já documentado), então duas linhas
 * do mesmo profissional com o mesmo nome e `organization_id` divergente são o mesmo item, não
 * dois estoques legítimos.
 */
final class LegacyInventoryGrouper
{
    /**
     * @return list<LegacyInventoryGroup>
     */
    public function group(Collection $inventories): array
    {
        return $inventories
            ->groupBy(fn (Inventory $inventory): string => $this->key($inventory))
            ->map(fn (Collection $rows): LegacyInventoryGroup => $this->toGroup($rows))
            ->values()
            ->all();
    }

    private function key(Inventory $inventory): string
    {
        return $inventory->professional_id.'|'.Str::lower(trim($inventory->item_name));
    }

    private function toGroup(Collection $rows): LegacyInventoryGroup
    {
        $sorted = $rows->sortBy('id')->values();
        $primary = $sorted->first();

        return new LegacyInventoryGroup(
            professionalId: $primary->professional_id,
            organizationId: $sorted->pluck('organization_id')->filter()->first(),
            name: $primary->item_name,
            category: $primary->category,
            rows: $sorted,
        );
    }
}
