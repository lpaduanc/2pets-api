<?php

namespace App\Services\Stock\Projection;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Support\BusinessClock;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Projeção de compra por produto (docs/gap-petflow/PLANO.md, 3.4), sobre o
 * `PurchaseProjectionCalculator`:
 *
 *  - janela de N dias (7/15/30/90) terminando hoje, por DIA de negócio (São Paulo): as saídas de
 *    venda e uso interno de cada dia formam a série do consumo e do σ;
 *  - perdas e vencimentos (`loss`, `expiry`) NÃO entram no consumo: voltam à parte (quantidade e
 *    valor) para a tela mostrar o indicador;
 *  - em pedido aberto = Σ (pedido − recebido) dos pedidos de compra em rascunho, enviados ou
 *    parcialmente recebidos (`PurchaseOrderStatus::isReceivable`);
 *  - parâmetros: prazo = o do produto, senão o do fornecedor padrão (o preferido, senão o da
 *    última compra), senão 15 dias; cobertura = o do produto, senão 30 dias; fator = o do
 *    produto, senão 1 (ou a menor fração da unidade, no produto fracionado).
 */
final class PurchaseProjectionService
{
    public const WINDOWS = [7, 15, 30, 90];

    public const DEFAULT_WINDOW = 30;

    public const DEFAULT_LEAD_TIME_DAYS = 15;

    public const DEFAULT_COVERAGE_DAYS = 30;

    private const DEMAND = [StockMovementType::SALE_OUT, StockMovementType::INTERNAL_USE];

    private const LOSSES = [StockMovementType::LOSS, StockMovementType::EXPIRY];

    public function __construct(private readonly PurchaseProjectionCalculator $calculator) {}

    /**
     * @param  Collection<int, Product>  $products  com `preferredSupplier` e `lastSupplier` carregados
     * @return array<int, array<string, mixed>> por `product_id`
     */
    public function project(Collection $products, int $window): array
    {
        $today = BusinessClock::today();
        $days = array_map(fn (int $i): string => $today->subDays($window - 1 - $i)->toDateString(), range(0, $window - 1));
        $ids = $products->pluck('id')->all();
        $demand = $this->dailyByProduct($ids, self::DEMAND, $days);
        $losses = $this->totalsByProduct($ids, self::LOSSES, $days);
        $open = $this->openOrders($ids);

        return $products->mapWithKeys(function (Product $product) use ($days, $demand, $losses, $open, $today, $window): array {
            $series = array_map(fn (string $day): string => $demand[$product->id][$day] ?? '0', $days);
            $lead = $this->leadTime($product);
            $coverage = $product->target_coverage_days ?? self::DEFAULT_COVERAGE_DAYS;
            $multiple = $this->multiple($product);
            $openQuantity = $open[$product->id] ?? '0';

            return [$product->id => [
                'window_days' => $window,
                'lead_time_days' => $lead,
                'target_coverage_days' => $coverage,
                'purchase_multiple' => $multiple,
                'use_safety_stock' => (bool) $product->use_safety_stock,
                'open_order_quantity' => $openQuantity,
                'losses' => $losses[$product->id] ?? ['quantity' => '0', 'value' => '0.00'],
                'supplier' => $this->supplier($product),
            ] + $this->calculator->project($series, (string) $product->stock_quantity, $openQuantity, $lead, $coverage, $multiple, (bool) $product->use_safety_stock, $today)];
        })->all();
    }

    public function leadTime(Product $product): int
    {
        return $product->lead_time_days
            ?? $product->preferredSupplier?->lead_time_days
            ?? $product->lastSupplier?->lead_time_days
            ?? self::DEFAULT_LEAD_TIME_DAYS;
    }

    /** @return array{id: int, name: string|null}|null */
    public function supplier(Product $product): ?array
    {
        $supplier = $product->preferredSupplier ?? $product->lastSupplier;

        return $supplier === null ? null : ['id' => $supplier->id, 'name' => $supplier->trade_name ?? $supplier->legal_name ?? null];
    }

    private function multiple(Product $product): string
    {
        if ($product->purchase_multiple !== null && (float) $product->purchase_multiple > 0) {
            return (string) $product->purchase_multiple;
        }

        $places = (int) $product->decimal_places_allowed;

        return $places > 0 ? '0.'.str_repeat('0', $places - 1).'1' : '1';
    }

    /**
     * @param  list<int>  $ids
     * @param  list<StockMovementType>  $types
     * @param  list<string>  $days
     * @return array<int, array<string, string>>
     */
    private function dailyByProduct(array $ids, array $types, array $days): array
    {
        $out = [];

        foreach ($this->movements($ids, $types, $days)->selectRaw('product_id, '.$this->localDay().' AS day, SUM(quantity) AS quantity')->groupByRaw('product_id, day')->get() as $row) {
            $out[(int) $row->product_id][$row->day] = (string) $row->quantity;
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @param  list<StockMovementType>  $types
     * @param  list<string>  $days
     * @return array<int, array{quantity: string, value: string}>
     */
    private function totalsByProduct(array $ids, array $types, array $days): array
    {
        return $this->movements($ids, $types, $days)->selectRaw('product_id, SUM(quantity) AS quantity, SUM(total_cost) AS value')->groupBy('product_id')->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->product_id => ['quantity' => (string) $row->quantity, 'value' => Money::of((string) $row->value)->toString()]])->all();
    }

    /**
     * @param  list<int>  $ids
     * @param  list<StockMovementType>  $types
     * @param  list<string>  $days
     */
    private function movements(array $ids, array $types, array $days): \Illuminate\Database\Query\Builder
    {
        $timezone = BusinessClock::timezone();
        $from = CarbonImmutable::parse($days[0], $timezone)->utc();
        $to = CarbonImmutable::parse(end($days), $timezone)->addDay()->utc();

        return DB::table('stock_movements')->whereIn('product_id', $ids === [] ? [0] : $ids)
            ->whereIn('type', array_map(fn (StockMovementType $t): string => $t->value, $types))
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to);
    }

    /** Dia de negócio de `occurred_at` (gravado em UTC, sem fuso). */
    private function localDay(): string
    {
        return "to_char((occurred_at AT TIME ZONE 'UTC') AT TIME ZONE '".BusinessClock::timezone()."', 'YYYY-MM-DD')";
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function openOrders(array $ids): array
    {
        $open = array_map(fn (PurchaseOrderStatus $s): string => $s->value, array_filter(PurchaseOrderStatus::cases(), fn (PurchaseOrderStatus $s): bool => $s->isReceivable()));

        return DB::table('purchase_order_items')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereIn('purchase_order_items.product_id', $ids === [] ? [0] : $ids)
            ->whereIn('purchase_orders.status', $open)->whereNull('purchase_orders.deleted_at')
            ->groupBy('purchase_order_items.product_id')
            ->selectRaw('purchase_order_items.product_id, SUM(GREATEST(purchase_order_items.quantity - purchase_order_items.received_quantity, 0)) AS quantity')
            ->pluck('quantity', 'product_id')->map(fn ($q): string => (string) $q)->all();
    }
}
