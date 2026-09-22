<?php

namespace App\Services\Stock;

use App\Enums\StockCountStatus;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Support\Facades\DB;

/**
 * Inventário (contagem física) — docs/gap-simplesvet/07.
 *
 * `open()` fotografa o saldo; `addCount()` grava contagens parciais (a contagem de uma loja
 * inteira leva horas e é feita em partes); `close()` gera TODOS os ajustes de uma vez, numa
 * transação, e registra quantos itens fecharam e quantos foram corrigidos — as colunas
 * "Estoque correto / Estoque corrigido" do SimplesVet.
 */
final class StockCountService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CommercialScopeResolver $scope,
    ) {}

    public function open(User $user, ?int $productGroupId = null, ?string $notes = null): StockCount
    {
        return DB::transaction(function () use ($user, $productGroupId, $notes): StockCount {
            $count = StockCount::create([
                'counted_at' => now(),
                'responsible_id' => $user->id,
                'product_group_id' => $productGroupId,
                'status' => StockCountStatus::OPEN,
                'notes' => $notes,
            ] + $this->scope->ownershipFor($user));

            $products = $this->scope->scopeQuery(Product::query(), $user)
                ->where('controls_stock', true)
                ->where('is_active', true)
                ->when($productGroupId !== null, fn ($q) => $q->where('product_group_id', $productGroupId))
                ->get(['id', 'stock_quantity']);

            $now = now();
            $count->items()->insert($products->map(fn (Product $product): array => [
                'stock_count_id' => $count->id,
                'product_id' => $product->id,
                'system_quantity' => (int) $product->stock_quantity,
                'counted_quantity' => null,
                'difference' => null,
                'adjusted' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            return $count;
        });
    }

    /**
     * Grava contagens. Produto que não estava na foto (cadastrado depois da abertura) entra
     * com o saldo atual como "sistema".
     *
     * @param  array<int, array{product_id: int, counted_quantity: int|null}>  $counts
     */
    public function addCount(StockCount $count, User $user, array $counts): StockCount
    {
        abort_unless($count->isOpen(), 422, 'Contagem já fechada.');

        DB::transaction(function () use ($count, $user, $counts): void {
            foreach ($counts as $entry) {
                $item = $count->items()->firstWhere('product_id', $entry['product_id']);

                if ($item === null) {
                    $product = $this->scope->scopeQuery(Product::query(), $user)
                        ->where('controls_stock', true)
                        ->findOrFail($entry['product_id']);

                    $item = $count->items()->make([
                        'product_id' => $product->id,
                        'system_quantity' => (int) $product->stock_quantity,
                    ]);
                }

                $counted = $entry['counted_quantity'];
                $item->counted_quantity = $counted;
                $item->difference = $counted === null ? null : $counted - $item->system_quantity;
                $item->save();
            }
        });

        return $count->fresh();
    }

    public function close(StockCount $count, User $user): StockCount
    {
        abort_unless($count->isOpen(), 422, 'Contagem já fechada.');

        return DB::transaction(function () use ($count, $user): StockCount {
            $correct = 0;
            $adjusted = 0;
            $value = 0.0;

            $items = $count->items()->whereNotNull('counted_quantity')->with('product')->orderBy('product_id')->get();

            foreach ($items as $item) {
                if ($item->difference === 0 || $item->product === null) {
                    $correct++;

                    continue;
                }

                // Aplica a DIFERENÇA sobre o saldo atual, não o número contado: venda feita
                // durante a contagem já saiu do saldo e não pode ser "desfeita" pelo ajuste.
                $target = (int) $item->product->fresh()->stock_quantity + $item->difference;
                $movement = $this->stock->adjustTo($item->product, $target, [
                    'reference' => $count,
                    'user' => $user,
                    'notes' => 'Inventário #'.$count->id,
                ]);

                if ($movement !== null) {
                    $value += $item->difference * (float) $movement->unit_cost;
                }

                $item->update(['adjusted' => true]);
                $adjusted++;
            }

            $count->update([
                'status' => StockCountStatus::CLOSED,
                'closed_at' => now(),
                'items_correct' => $correct,
                'items_adjusted' => $adjusted,
                'adjustment_value' => round($value, 2),
            ]);

            return $count->fresh();
        });
    }
}
