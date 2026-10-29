<?php

namespace App\Services\Stock;

use App\Enums\StockDirection;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money\Decimal;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
 *  - toda entrada pondera o custo médio pelo valor exato do estoque (`StockValuation`); toda
 *    saída sai pelo médio, subtraindo do valor — a soma dos custos que saíram mais o valor que
 *    ficou é igual ao que entrou, sem resíduo de arredondamento;
 *  - compra também grava o último custo.
 *
 * `$context` aceito por `in()`/`out()`:
 *  - `unit_cost` (string|float)      custo unitário; padrão = custo médio vigente
 *  - `total_cost` (string)           valor EXATO da entrada (linha da nota); sem ele, quantidade ×
 *                                    `unit_cost`. Compra manda este — dividir para achar o custo
 *                                    unitário e multiplicar de volta perderia a fração.
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
    public function in(Product $product, StockMovementType $type, Quantity|int|string $quantity, array $context = []): array
    {
        $this->assertDirection($type, StockDirection::IN);

        return $this->move($product, $type, Quantity::of($quantity), $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<StockMovement>
     */
    public function out(Product $product, StockMovementType $type, Quantity|int|string $quantity, array $context = []): array
    {
        $this->assertDirection($type, StockDirection::OUT);

        return $this->move($product, $type, Quantity::of($quantity), $context);
    }

    /**
     * Leva o saldo a um valor-alvo com um ajuste (inventário, correção do cadastro). Devolve
     * TODOS os movimentos — uma falta que atravessa lotes sai em vários pedaços, e quem soma o
     * valor do ajuste precisa de todos. Nada a fazer quando o saldo já é o alvo.
     *
     * @param  array<string, mixed>  $context
     * @return list<StockMovement>
     */
    public function adjustTo(Product $product, Quantity|int|string $target, array $context = []): array
    {
        $target = Quantity::of($target);

        return DB::transaction(function () use ($product, $target, $context): array {
            $locked = $this->lock($product);

            if ($locked === null || ! $locked->controls_stock) {
                return [];
            }

            $difference = $target->minus(Quantity::of($locked->stock_quantity));

            if ($difference->isZero()) {
                return [];
            }

            $type = $difference->isPositive() ? StockMovementType::ADJUSTMENT_IN : StockMovementType::ADJUSTMENT_OUT;

            return $this->move($product, $type, $difference->abs(), $context);
        });
    }

    /**
     * Valor com que `$quantity` unidades VOLTAM ao estoque (cancelamento/devolução de venda):
     * a parte do valor que saiu nos movimentos `$outType` do documento `$reference`, pelo rateio
     * acumulado — com `$alreadyBack` já devolvidas, devolver tudo, em quantas vezes for, repõe
     * exatamente o valor que saiu. `null` quando o documento não tem saída deste produto.
     */
    public function returningValue(Product $product, Model $reference, StockMovementType $outType, Quantity $alreadyBack, Quantity $quantity): ?string
    {
        $out = StockMovement::query()
            ->where('product_id', $product->getKey())
            ->where('reference_type', $reference->getMorphClass())
            ->where('reference_id', $reference->getKey())
            ->where('type', $outType->value)
            ->selectRaw('COALESCE(SUM(quantity), 0) AS quantity, COALESCE(SUM(total_cost), 0) AS value')
            ->first();

        $outQuantity = Quantity::of($out?->quantity ?? 0);

        if (! $outQuantity->isPositive()) {
            return null;
        }

        $value = Decimal::of($out->value);
        $cumulative = fn (Quantity $back): BigDecimal => $back->isGreaterThanOrEqualTo($outQuantity)
            ? $value->toScale(StockValuation::VALUE_SCALE)
            : self::proportion($value, $back, $outQuantity);

        return (string) $cumulative($alreadyBack->plus($quantity))->minus($cumulative($alreadyBack));
    }

    /**
     * Reavaliação manual do custo médio (cadastro do produto). Só age quando o médio MUDA de
     * fato; a diferença de valor vira ajuste de custo no kardex.
     *
     * @param  array<string, mixed>  $context
     */
    public function revalue(Product $product, string $averageCost, array $context = []): ?StockMovement
    {
        return DB::transaction(function () use ($product, $averageCost, $context): ?StockMovement {
            $locked = $this->lock($product);

            if ($locked === null || Decimal::of($locked->average_cost)->isEqualTo(Decimal::of($averageCost))) {
                return null;
            }

            [$after, $variance] = $this->averageCost->revalued(StockValuation::of($locked), $averageCost);
            $movement = $locked->controls_stock ? $this->recordVariance($locked, $after, $variance, $context) : null;

            $locked->forceFill($after->toAttributes())->saveQuietly();
            $product->setRawAttributes($locked->getAttributes(), true);

            return $movement;
        });
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<StockMovement>
     */
    private function move(Product $product, StockMovementType $type, Quantity $quantity, array $context): array
    {
        if (! $quantity->isPositive()) {
            throw new InvalidArgumentException('Quantidade de movimento de estoque deve ser positiva.');
        }

        return DB::transaction(function () use ($product, $type, $quantity, $context): array {
            $locked = $this->lock($product);

            if ($locked === null || ! $locked->controls_stock) {
                return [];
            }

            $movements = match (true) {
                $type->direction() === StockDirection::IN => $this->enter($locked, $type, $quantity, $context),
                ($context['reverse_average_cost'] ?? false) === true => $this->reverse($locked, $type, $quantity, $context),
                default => $this->leave($locked, $type, $quantity, $context),
            };

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
     * @return list<StockMovement>
     */
    private function enter(Product $locked, StockMovementType $type, Quantity $quantity, array $context): array
    {
        $before = StockValuation::of($locked);
        $value = $this->entryValue($quantity, $before, $context);
        $unitCost = self::unitCost($value, $quantity);
        [$after, $variance] = $this->averageCost->afterEntry($before, $quantity, $value);

        $batch = $this->resolveEntryBatch($locked, $context, $unitCost);
        $this->shiftBatch($batch, $quantity);
        $movements = [$this->record($locked, $type, $quantity, $after->quantity, $unitCost, $value, $batch, $context)];
        $movements = [...$movements, ...array_filter([$this->recordVariance($locked, $after, $variance, $context)])];

        $attributes = $after->toAttributes();

        if ($type === StockMovementType::PURCHASE_IN) {
            $attributes['last_cost'] = (string) $unitCost;
        }

        $locked->forceFill($attributes)->saveQuietly();

        return $movements;
    }

    /**
     * Saída pelo custo médio, FEFO por lote. Cada pedaço passa pela MESMA cadeia de valuation:
     * a soma do valor dos pedaços é exatamente o valor que saiu do estoque.
     *
     * @param  array<string, mixed>  $context
     * @return list<StockMovement>
     */
    private function leave(Product $locked, StockMovementType $type, Quantity $quantity, array $context): array
    {
        $valuation = StockValuation::of($locked);
        $movements = [];

        foreach ($this->resolveExitChunks($locked, $context, $quantity) as [$batch, $chunk]) {
            $averageCost = $valuation->averageCost;
            [$valuation, $exitValue] = $this->averageCost->afterExit($valuation, $chunk);

            $this->shiftBatch($batch, $chunk->negated());
            $movements[] = $this->record($locked, $type, $chunk, $valuation->quantity, $averageCost, $exitValue, $batch, $context);
        }

        $locked->forceFill($valuation->toAttributes())->saveQuietly();

        return $movements;
    }

    /**
     * Saída que desfaz uma entrada (compra cancelada): sai com o valor com que entrou. O valor é
     * repartido entre os pedaços (lotes) proporcionalmente; o último leva o que sobra, para a
     * soma bater exata com o valor da entrada.
     *
     * @param  array<string, mixed>  $context
     * @return list<StockMovement>
     */
    private function reverse(Product $locked, StockMovementType $type, Quantity $quantity, array $context): array
    {
        $before = StockValuation::of($locked);
        $value = $this->entryValue($quantity, $before, $context);
        $unitCost = self::unitCost($value, $quantity);
        [$after, $variance] = $this->averageCost->afterReversal($before, $quantity, $value);

        $chunks = $this->resolveExitChunks($locked, $context, $quantity);
        $balance = $before->quantity;
        $pending = $value;
        $movements = [];

        foreach ($chunks as $index => [$batch, $chunk]) {
            $chunkValue = $index === array_key_last($chunks) ? $pending : self::proportion($value, $chunk, $quantity);
            $pending = $pending->minus($chunkValue);
            $balance = $balance->minus($chunk);

            $this->shiftBatch($batch, $chunk->negated());
            $movements[] = $this->record($locked, $type, $chunk, $balance, $unitCost, $chunkValue, $batch, $context);
        }

        $movements = [...$movements, ...array_filter([$this->recordVariance($locked, $after, $variance, $context)])];
        $locked->forceFill($after->toAttributes())->saveQuietly();

        return $movements;
    }

    /**
     * Ajuste de custo (quantidade zero) quando a valuation corrigiu o valor do estoque. Sinal
     * positivo = valor que voltou ao estoque (crédito); negativo = custo a mais (débito).
     *
     * @param  array<string, mixed>  $context
     */
    private function recordVariance(Product $locked, StockValuation $after, BigDecimal $variance, array $context): ?StockMovement
    {
        if ($variance->isZero()) {
            return null;
        }

        $type = $variance->isPositive() ? StockMovementType::COST_VARIANCE_IN : StockMovementType::COST_VARIANCE_OUT;
        $context = ['notes' => 'Ajuste de custo: diferença entre o custo estimado e o real.'] + $context;

        return $this->record($locked, $type, Quantity::zero(), $after->quantity, BigDecimal::zero(), $variance->abs(), null, $context);
    }

    /**
     * Valor exato do que entra (ou do que uma reversão tira): `total_cost` quando o chamador o
     * tem (linha da nota), senão quantidade × custo unitário — multiplicação, sem perda.
     *
     * @param  array<string, mixed>  $context
     */
    private function entryValue(Quantity $quantity, StockValuation $current, array $context): BigDecimal
    {
        if (isset($context['total_cost'])) {
            return Decimal::of($context['total_cost'])->toScale(StockValuation::VALUE_SCALE, RoundingMode::HalfUp);
        }

        $unitCost = isset($context['unit_cost']) ? Decimal::of($context['unit_cost']) : $current->averageCost;

        return $quantity->toBigDecimal()->multipliedBy($unitCost)->toScale(StockValuation::VALUE_SCALE, RoundingMode::HalfUp);
    }

    private static function unitCost(BigDecimal $value, Quantity $quantity): BigDecimal
    {
        return $value->dividedBy($quantity->toBigDecimal(), StockValuation::COST_SCALE, RoundingMode::HalfUp);
    }

    private static function proportion(BigDecimal $value, Quantity $part, Quantity $whole): BigDecimal
    {
        return $value->multipliedBy($part->toBigDecimal())
            ->dividedBy($whole->toBigDecimal(), StockValuation::VALUE_SCALE, RoundingMode::HalfUp);
    }

    private function shiftBatch(?ProductBatch $batch, Quantity $delta): void
    {
        if ($batch === null) {
            return;
        }

        $batch->quantity = Quantity::of($batch->quantity ?? 0)->plus($delta)->toString();
        $batch->save();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveEntryBatch(Product $product, array $context, BigDecimal $unitCost): ?ProductBatch
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
        $batch->unit_cost = (string) $unitCost;
        $batch->quantity ??= '0';

        return $batch;
    }

    /**
     * Quebra a saída entre lotes, FEFO. Lote sem validade vai por último; o que os lotes não
     * cobrem sai "sem lote" (estoque negativo permitido). Lote explícito ignora o FEFO.
     *
     * @param  array<string, mixed>  $context
     * @return list<array{0: ProductBatch|null, 1: Quantity}>
     */
    private function resolveExitChunks(Product $product, array $context, Quantity $quantity): array
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
            if ($remaining->isZero()) {
                break;
            }

            $take = $remaining->min(Quantity::of($batch->quantity));
            $chunks[] = [$batch, $take];
            $remaining = $remaining->minus($take);
        }

        if ($remaining->isPositive()) {
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
        Quantity $quantity,
        Quantity $balanceAfter,
        BigDecimal $unitCost,
        BigDecimal $totalCost,
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
            'quantity' => $quantity->toString(),
            'unit_cost' => (string) $unitCost->toScale(StockValuation::COST_SCALE, RoundingMode::HalfUp),
            'total_cost' => (string) $totalCost->toScale(StockValuation::VALUE_SCALE, RoundingMode::HalfUp),
            'balance_after' => $balanceAfter->toString(),
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
