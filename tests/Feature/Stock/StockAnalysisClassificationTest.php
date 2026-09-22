<?php

namespace Tests\Feature\Stock;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Stock\Concerns\BuildsStockFixtures;
use Tests\TestCase;

/**
 * Critério de aceite do doc 07: "classificação de giro devolve os 6 grupos com capital
 * imobilizado e soma igual ao total".
 */
class StockAnalysisClassificationTest extends TestCase
{
    use BuildsStockFixtures;
    use RefreshDatabase;

    public function test_products_fall_into_each_situation_and_groups_sum_to_the_total(): void
    {
        $this->buildStockClinic();

        $old = now()->subDays(200);

        $restock = $this->seedProduct('Repor', 2, 10, $old, [['out', 1, now()->subDays(5)]], ['min_stock' => 3]);
        $new = $this->seedProduct('Novo', 20, 10, now()->subDays(10), [], []);
        $stagnant = $this->seedProduct('Parado', 30, 10, $old, [['out', 2, now()->subDays(150)]], []);
        // Saída de 9 em 90 dias = 0,1/dia; saldo 50 → cobertura 500 dias.
        $excess = $this->seedProduct('Excesso', 50, 10, $old, [['out', 9, now()->subDays(20)]], []);
        // Saída de 90 em 90 dias = 1/dia; saldo 40 → cobertura 40 dias.
        $adequate = $this->seedProduct('Adequado', 40, 10, $old, [['out', 90, now()->subDays(30)]], []);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/reports/stock-analysis')
            ->assertOk();

        $situations = collect($response->json('data'))->pluck('situation', 'product.id');
        $this->assertSame('restock', $situations[$restock->id]);
        $this->assertSame('new', $situations[$new->id]);
        $this->assertSame('stagnant', $situations[$stagnant->id]);
        $this->assertSame('excess', $situations[$excess->id]);
        $this->assertSame('adequate', $situations[$adequate->id]);

        $summary = $response->json('summary');
        $groups = collect($summary)->except('all');
        $this->assertSame(5, $summary['all']['count']);
        $this->assertEqualsWithDelta($summary['all']['value'], $groups->sum('value'), 0.001);
        $this->assertSame($summary['all']['count'], $groups->sum('count'));
        $this->assertEquals(300, $summary['stagnant']['value']); // 30 × 10,00

        $this->getJson('/api/professional/reports/stock-analysis?situation=excess')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.id', $excess->id)
            ->assertJsonPath('criteria.window_days', 90);
    }

    public function test_expiring_report_lists_expired_and_soon_to_expire_batches(): void
    {
        $this->buildStockClinic();
        $stock = app(StockService::class);

        $batched = $this->product(['track_batches' => true]);
        $stock->in($batched, StockMovementType::PURCHASE_IN, 3, ['batch_code' => 'VENCIDO', 'expires_at' => now()->subDays(2)->toDateString(), 'unit_cost' => 4]);
        $stock->in($batched, StockMovementType::PURCHASE_IN, 3, ['batch_code' => 'LONGE', 'expires_at' => now()->addYear()->toDateString()]);

        $plain = $this->product(['expiry_date' => now()->addDays(10)->toDateString()]);
        $stock->in($plain, StockMovementType::OPENING_BALANCE, 1);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/reports/stock-expiring?days=30')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('expired_count', 1)
            ->assertJsonPath('data.0.batch.batch_code', 'VENCIDO')
            ->assertJsonPath('data.0.capital', 12);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: \DateTimeInterface}>  $outs
     * @param  array<string, mixed>  $attributes
     */
    private function seedProduct(string $name, int $finalStock, float $cost, \DateTimeInterface $firstIn, array $outs, array $attributes): Product
    {
        $product = $this->product(['name' => $name, 'average_cost' => $cost] + $attributes);
        $stock = app(StockService::class);
        $totalOut = array_sum(array_column($outs, 1));

        $stock->in($product, StockMovementType::OPENING_BALANCE, $finalStock + $totalOut, ['occurred_at' => $firstIn, 'unit_cost' => $cost]);

        foreach ($outs as [, $quantity, $at]) {
            $stock->out($product, StockMovementType::SALE_OUT, $quantity, ['occurred_at' => $at]);
        }

        return $product->fresh();
    }
}
