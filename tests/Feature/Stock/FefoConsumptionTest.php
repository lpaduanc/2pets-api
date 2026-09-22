<?php

namespace Tests\Feature\Stock;

use App\Enums\StockMovementType;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Stock\Concerns\BuildsStockFixtures;
use Tests\TestCase;

/** Critério de aceite do doc 07: "baixa FEFO consome primeiro o lote que vence antes". */
class FefoConsumptionTest extends TestCase
{
    use BuildsStockFixtures;
    use RefreshDatabase;

    public function test_exit_consumes_the_batch_that_expires_first_and_splits_across_batches(): void
    {
        $this->buildStockClinic();
        $product = $this->product(['track_batches' => true]);
        $stock = app(StockService::class);

        $stock->in($product, StockMovementType::PURCHASE_IN, 4, ['batch_code' => 'LATE', 'expires_at' => '2027-06-01', 'unit_cost' => 10]);
        $stock->in($product, StockMovementType::PURCHASE_IN, 3, ['batch_code' => 'SOON', 'expires_at' => '2026-12-01', 'unit_cost' => 10]);
        $stock->in($product, StockMovementType::PURCHASE_IN, 5, ['batch_code' => 'NOEXP', 'unit_cost' => 10]);

        $movements = $stock->out($product, StockMovementType::SALE_OUT, 5);

        $this->assertCount(2, $movements);
        $this->assertSame(['SOON', 'LATE'], array_map(fn (StockMovement $m) => $m->batch->batch_code, $movements));
        $this->assertSame([3, 2], array_map(fn (StockMovement $m) => $m->quantity, $movements));
        $this->assertSame([9, 7], array_map(fn (StockMovement $m) => $m->balance_after, $movements));

        $batches = $product->batches()->pluck('quantity', 'batch_code')->all();
        $this->assertSame(['LATE' => 2, 'SOON' => 0, 'NOEXP' => 5], $batches);
        $this->assertLedgerMatches($product);
    }

    public function test_exit_beyond_batches_goes_negative_without_batch(): void
    {
        $this->buildStockClinic();
        $product = $this->product(['track_batches' => true]);
        $stock = app(StockService::class);

        $stock->in($product, StockMovementType::PURCHASE_IN, 2, ['batch_code' => 'A', 'expires_at' => '2027-01-01']);
        $movements = $stock->out($product, StockMovementType::SALE_OUT, 3);

        $this->assertSame(['A', null], array_map(fn (StockMovement $m) => $m->batch?->batch_code, $movements));
        $this->assertSame(-1, $product->fresh()->stock_quantity);
        $this->assertLedgerMatches($product);
    }
}
