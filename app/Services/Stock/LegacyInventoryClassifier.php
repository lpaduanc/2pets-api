<?php

namespace App\Services\Stock;

use App\Enums\InventoryCategory;
use App\Enums\ProductPurpose;

/**
 * Mapa de migração da spec de consolidação (docs/gap-simplesvet/specs/
 * produtos-estoque-consolidado-spec.md, item 1): `purpose` responde "pode vender avulso no
 * balcão?"; a antiga `InventoryCategory` respondia "que tipo de insumo é, para escolher no ato
 * e agrupar a compra" — essa segunda pergunta não vira enum novo em `Product`, vira
 * `product_group_id` (grupo comercial já existente, livre por organização).
 */
final class LegacyInventoryClassifier
{
    /** @var array<string, string> */
    private const GROUP_NAMES = [
        InventoryCategory::VACCINE->value => 'Vacinas',
        InventoryCategory::MEDICATION->value => 'Medicamentos',
        InventoryCategory::SUPPLY->value => 'Insumos',
        InventoryCategory::EQUIPMENT->value => 'Equipamentos',
    ];

    /**
     * Vacina/medicamento nascem `consumable` (uso durante o atendimento); a spec reserva
     * `resale` para o caso raro de dispensa avulsa ao tutor, decidido item a item na migração —
     * nunca em massa. Insumo e equipamento nunca aparecem como linha de venda ao tutor.
     */
    public function purposeFor(InventoryCategory $category): ProductPurpose
    {
        return match ($category) {
            InventoryCategory::SUPPLY, InventoryCategory::EQUIPMENT => ProductPurpose::INTERNAL_USE,
            default => ProductPurpose::CONSUMABLE,
        };
    }

    public function groupNameFor(InventoryCategory $category): string
    {
        return self::GROUP_NAMES[$category->value];
    }

    /**
     * Item 1a da spec: equipamento é patrimônio, não estoque consumível — não controla saldo
     * decrementável, não entra na lista de preços, não entra na análise de giro.
     */
    public function controlsStockFor(InventoryCategory $category): bool
    {
        return $category !== InventoryCategory::EQUIPMENT;
    }

    /**
     * `unit_of_sale` é `varchar(10)`; `inventories.unit` era texto livre sem limite
     * ("comprimidos", "unidade"). Trunca em vez de rejeitar — a migração não pode falhar por
     * causa de um texto de exibição.
     */
    public function unitOfSaleFor(?string $legacyUnit): string
    {
        $unit = strtoupper(trim((string) $legacyUnit));

        return $unit === '' ? 'UN' : substr($unit, 0, 10);
    }
}
