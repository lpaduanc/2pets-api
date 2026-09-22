<?php

namespace App\Services\Stock;

use App\Enums\StockDirection;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ÚNICA porta de entrada para mexer no saldo de `products` — docs/gap-simplesvet/07.
 *
 * Toda mudança de saldo vira um `stock_movements` com `balance_after`, na mesma transação e
 * sob `lockForUpdate` do produto. O saldo é SEMPRE lido da linha travada, nunca do model que
 * o chamador tem na mão: duas vendas simultâneas lendo o mesmo `stock_quantity` e gravando o
 * mesmo decremento é exatamente a corrida que o lock existe para impedir
 * (`ConcurrentStockOutTest`).
 *
 * Regras que moram aqui e não vazam:
 *  - produto com `controls_stock = false` (serviço, manipulado) NÃO gera movimento;
 *  - estoque negativo é permitido (o SimplesVet não bloqueia a venda) — só sinalizado;
 *  - saída de produto com lote consome FEFO (primeiro a vencer, primeiro a sair);
 *  - entrada de compra recalcula o custo médio ponderado e o último custo.
 *
 * `$context` aceito por `in()`/`out()`:
 *  - `unit_cost` (float)             custo unitário; padrão = custo médio vigente
 *  - `batch_id` (int)                lote explícito
 *  - `batch_code`/`expires_at`       lote de uma entrada (cria o lote se não existir)
 *  - `reason_id` (int)               motivo de saída
 *  - `reference` (Model)             documento de origem (venda, compra, inventário...)
 *  - `user` (User|int|null)          quem lançou
 *  - `notes` (string)                observação
 *  - `occurred_at` (CarbonInterface) data do fato; padrão = agora
 *  - `reverse_average_cost` (bool)   saída que desfaz uma entrada de compra (cancelamento)
 */
final class StockService
{
    public function __construct(private readonly AverageCostCalculator $averageCost) {}

    /**
     * @param  array<string, mixed>  $context
     * @return list<StockMovement>
     */
    public function in(Product $product, StockMovementType $type, int $quantity, array $context = []): array
    {
        $this->assertDirection($type, StockDirection::IN);

        return $this->move($product, $type, $quantity, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<StockMovement>
     */
    public function out(Product $product, StockMovementType $type, int $quantity, array $context = []): array
    {
        $this->assertDirection($type, StockDirection::OUT);

        return $this->move($product, $type, $quantity, $context);
    }

    /**
     * Leva o saldo a um valor-alvo com um único movimento de ajuste (inventário, correção do
     * cadastro). Nada a fazer quando o saldo já é o alvo.
     *
     * @param  array<string, mixed>  $context
     */
    public function adjustTo(Product $product, int $target, array $context = []): ?StockMovement
    {
        return DB::transaction(function () use ($product, $target, $context): ?StockMovement {
            $locked = $this->lock($product);

            if ($locked === null || ! $locked->controls_stock) {
                return null;
            }

            $difference = $target - (int) $locked->stock_quantity;

            if ($difference === 0) {
                return null;
            }

            $type = $difference > 0 ? StockMovementType::ADJUSTMENT_IN : StockMovementType::ADJUSTMENT_OUT;
            $movements = $this->move($product, $type, abs($difference), $context);

            return $movements[array_key_last($movements)] ?? null;
        });
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<StockMovement>
     */
    private function move(Product $product, StockMovementType $type, int $quantity, array $context): array
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantidade de movimento de estoque deve ser positiva.');
        }

        return DB::transaction(function () use ($product, $type, $quantity, $context): array {
            $locked = $this->lock($product);

            if ($locked === null || ! $locked->controls_stock) {
                return [];
            }

            $balance = (int) $locked->stock_quantity;
            $unitCost = array_key_exists('unit_cost', $context) && $context['unit_cost'] !== null
                ? round((float) $context['unit_cost'], 4)
                : (float) $locked->average_cost;

            $chunks = $type->direction() === StockDirection::IN
                ? [[$this->resolveEntryBatch($locked, $context, $unitCost), $quantity]]
                : $this->resolveExitChunks($locked, $context, $quantity);

            $movements = [];

            foreach ($chunks as [$batch, $chunkQuantity]) {
                $balance += $chunkQuantity * $type->direction()->sign();

                if ($batch !== null) {
                    $batch->quantity += $chunkQuantity * $type->direction()->sign();
                    $batch->save();
                }

                $movements[] = $this->record($locked, $type, $chunkQuantity, $balance, $unitCost, $batch, $context);
            }

            $this->persistBalance($locked, $type, $quantity, $unitCost, $balance, $context);

            // Devolve o saldo novo para o model de quem chamou — sem isto, a tela que acabou de
            // vender leria o número antigo.
            $product->setRawAttributes($locked->getAttributes(), true);

            return $movements;
        });
    }

    private function lock(Product $product): ?Product
    {
        return Product::withTrashed()->whereKey($product->getKey())->lockForUpdate()->first();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function persistBalance(Product $locked, StockMovementType $type, int $quantity, float $unitCost, int $balance, array $context): void
    {
        $before = (int) $locked->stock_quantity;
        $attributes = ['stock_quantity' => $balance];

        if ($type === StockMovementType::PURCHASE_IN) {
            $attributes['average_cost'] = $this->averageCost->afterEntry($before, (float) $locked->average_cost, $quantity, $unitCost);
            $attributes['last_cost'] = $unitCost;
        }

        if (($context['reverse_average_cost'] ?? false) === true) {
            $attributes['average_cost'] = $this->averageCost->afterReversal($before, (float) $locked->average_cost, $quantity, $unitCost);
        }

        $locked->forceFill($attributes)->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveEntryBatch(Product $product, array $context, float $unitCost): ?ProductBatch
    {
        if (! $product->track_batches) {
            return null;
        }

        if (! empty($context['batch_id'])) {
            return $product->batches()->lockForUpdate()->findOrFail((int) $context['batch_id']);
        }

        if (empty($context['batch_code'])) {
            return null;
        }

        $batch = $product->batches()->lockForUpdate()->firstOrNew(['batch_code' => (string) $context['batch_code']]);
        $batch->expires_at ??= $context['expires_at'] ?? null;
        $batch->unit_cost = $unitCost;
        $batch->quantity ??= 0;

        return $batch;
    }

    /**
     * Quebra a saída entre lotes, FEFO. Lote sem validade vai por último; o que os lotes não
     * cobrem sai "sem lote" (estoque negativo permitido). Lote explícito ignora o FEFO.
     *
     * @param  array<string, mixed>  $context
     * @return list<array{0: ProductBatch|null, 1: int}>
     */
    private function resolveExitChunks(Product $product, array $context, int $quantity): array
    {
        if (! $product->track_batches) {
            return [[null, $quantity]];
        }

        if (! empty($context['batch_id'])) {
            return [[$product->batches()->lockForUpdate()->findOrFail((int) $context['batch_id']), $quantity]];
        }

        $batches = $product->batches()
            ->where('quantity', '>', 0)
            ->orderByRaw('expires_at IS NULL, expires_at ASC, id ASC')
            ->lockForUpdate()
            ->get();

        $chunks = [];
        $remaining = $quantity;

        foreach ($batches as $batch) {
            if ($remaining === 0) {
                break;
            }

            $take = min($remaining, $batch->quantity);
            $chunks[] = [$batch, $take];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $chunks[] = [null, $remaining];
        }

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(
        Product $product,
        StockMovementType $type,
        int $quantity,
        int $balanceAfter,
        float $unitCost,
        ?ProductBatch $batch,
        array $context,
    ): StockMovement {
        $reference = $context['reference'] ?? null;
        $user = $context['user'] ?? null;
        $occurredAt = $context['occurred_at'] ?? null;

        return StockMovement::create([
            'organization_id' => $product->organization_id,
            'professional_id' => $product->professional_id,
            'product_id' => $product->id,
            'batch_id' => $batch?->id,
            'type' => $type,
            'direction' => $type->direction(),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => round($quantity * $unitCost, 2),
            'balance_after' => $balanceAfter,
            'reason_id' => $context['reason_id'] ?? null,
            'reference_type' => $reference instanceof Model ? $reference->getMorphClass() : null,
            'reference_id' => $reference instanceof Model ? $reference->getKey() : null,
            'occurred_at' => $occurredAt instanceof CarbonInterface ? $occurredAt : ($occurredAt ?? now()),
            'user_id' => $user instanceof User ? $user->id : $user,
            'notes' => $context['notes'] ?? null,
        ]);
    }

    private function assertDirection(StockMovementType $type, StockDirection $expected): void
    {
        if ($type->direction() !== $expected) {
            throw new InvalidArgumentException(sprintf(
                'Tipo %s não é um movimento de %s.',
                $type->value,
                $expected === StockDirection::IN ? 'entrada' : 'saída'
            ));
        }
    }
}
