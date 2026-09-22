<?php

namespace App\Services\Stock;

use App\Enums\InventoryMovementType;
use App\Enums\StockMovementType;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;

/**
 * Item 5.3 da spec de consolidação: `InventoryMovement` (sem `balance_after`) e `StockMovement`
 * (com `balance_after`, invariante testada por `StockLedgerIntegrityTest`) não são
 * intercambiáveis linha a linha — precisa de REPLAY cronológico através de `StockService`, que
 * é quem sabe calcular o saldo corrente, não uma cópia de coluna.
 *
 * Item de estoque criado antes de `inventory_movements` existir (todo seed que chamava
 * `Inventory::create()` direto, sem passar por `InventoryController`) não tem histórico
 * nenhum — o replay termina em 0 e a reconciliação final cobre a diferença com um único
 * movimento de ajuste, documentado como tal. Nenhum saldo é perdido nos dois casos.
 */
final class LegacyStockLedgerReplay
{
    /** @var array<string, StockMovementType> */
    private const TYPE_MAP = [
        InventoryMovementType::PURCHASE_IN->value => StockMovementType::PURCHASE_IN,
        InventoryMovementType::ADJUSTMENT_INCREASE->value => StockMovementType::ADJUSTMENT_IN,
        InventoryMovementType::ADJUSTMENT_DECREASE->value => StockMovementType::ADJUSTMENT_OUT,
        InventoryMovementType::OUT_VACCINATION->value => StockMovementType::INTERNAL_USE,
        InventoryMovementType::OUT_DEWORMING->value => StockMovementType::INTERNAL_USE,
        InventoryMovementType::LOSS->value => StockMovementType::LOSS,
    ];

    public function __construct(private readonly StockService $stock) {}

    /**
     * Repõe o histórico de `$row` sobre `$product` (num lote próprio quando `$batchCode` é
     * informado) e garante, ao final, que o saldo contribuído por esta linha bate exatamente
     * com `$row->quantity` — nunca deixa a migração terminar com um número diferente do
     * cadastro original.
     */
    public function replay(Product $product, Inventory $row, ?string $batchCode): void
    {
        $replayedBalance = 0;

        foreach ($row->movements()->orderBy('created_at')->orderBy('id')->get() as $movement) {
            $replayedBalance += $this->applyMovement($product, $row, $movement, $batchCode);
        }

        $this->reconcile($product, $row, $batchCode, $replayedBalance);
    }

    private function applyMovement(Product $product, Inventory $row, InventoryMovement $movement, ?string $batchCode): int
    {
        $type = self::TYPE_MAP[$movement->type->value] ?? null;
        $quantity = abs($movement->quantity_delta);

        if ($type === null || $quantity === 0) {
            return 0;
        }

        $context = $this->contextFor($row, $movement, $batchCode);
        $movements = $movement->quantity_delta > 0
            ? $this->stock->in($product, $type, $quantity, $context)
            : $this->stock->out($product, $type, $quantity, $context);

        return $movements === [] ? 0 : $movement->quantity_delta;
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFor(Inventory $row, InventoryMovement $movement, ?string $batchCode): array
    {
        return [
            'batch_code' => $batchCode,
            'expires_at' => $row->expiry_date,
            'unit_cost' => (float) ($row->cost_price ?? 0),
            'reference' => $this->resolveReference($movement),
            'user' => $movement->professional_id,
            'occurred_at' => $movement->created_at,
            'notes' => $movement->notes ?? 'Migrado do livro legado de inventories (inventory_movements).',
        ];
    }

    private function resolveReference(InventoryMovement $movement): ?object
    {
        if ($movement->reference_type === null || $movement->reference_id === null) {
            return null;
        }

        return class_exists($movement->reference_type)
            ? $movement->reference_type::withTrashed()->find($movement->reference_id)
            : null;
    }

    /**
     * Saldo residual não coberto pelo livro legado (item pré-ledger ou movimento com tipo fora
     * do mapa) vira UM movimento de ajuste, nunca uma correção silenciosa da coluna.
     */
    private function reconcile(Product $product, Inventory $row, ?string $batchCode, int $replayedBalance): void
    {
        $difference = (int) $row->quantity - $replayedBalance;

        if ($difference === 0) {
            return;
        }

        $type = $difference > 0 ? StockMovementType::ADJUSTMENT_IN : StockMovementType::ADJUSTMENT_OUT;
        $context = [
            'batch_code' => $batchCode,
            'expires_at' => $row->expiry_date,
            'unit_cost' => (float) ($row->cost_price ?? 0),
            'notes' => 'Saldo residual reconciliado na migração de inventories → products (sem histórico de movimento correspondente).',
        ];

        $difference > 0
            ? $this->stock->in($product, $type, abs($difference), $context)
            : $this->stock->out($product, $type, abs($difference), $context);
    }
}
