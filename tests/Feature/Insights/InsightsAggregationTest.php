<?php

namespace Tests\Feature\Insights;

use App\Enums\SaleStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Insights\Concerns\BuildsInsightsFixtures;
use Tests\TestCase;

/**
 * Critérios de aceite de docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md:
 * regra de negócio 1 (ticket médio) e 3 (dia × semana coerentes).
 */
class InsightsAggregationTest extends TestCase
{
    use BuildsInsightsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_net_sales_and_average_ticket_match_the_sales_created_in_the_period(): void
    {
        $today = CarbonImmutable::now();

        $this->sellProduct($this->vetMember, price: 100, soldAt: $today);
        $this->sellProduct($this->vetMember, price: 50, soldAt: $today, discount: 10);

        $response = $this->actingAs($this->owner, 'sanctum')->getJson(
            '/api/professional/insights/sales?'.http_build_query([
                'from' => $today->toDateString(),
                'to' => $today->toDateString(),
                'dimension' => 'date',
                'granularity' => 'day',
            ])
        )->assertOk();

        // Líquido: 100 + (50 - 10) = 140, em 2 vendas -> ticket médio 70.
        $response->assertJsonPath('totals.net_sales', 140)
            ->assertJsonPath('totals.sale_count', 2)
            ->assertJsonPath('totals.avg_ticket', 70)
            ->assertJsonPath('totals.discounts', 10);
    }

    public function test_sum_of_weekly_buckets_equals_sum_of_daily_buckets_in_the_same_period(): void
    {
        $monday = CarbonImmutable::now()->startOfWeek();

        $this->sellProduct($this->vetMember, price: 80, soldAt: $monday);
        $this->sellProduct($this->vetMember, price: 120, soldAt: $monday->addDays(3));

        $range = [
            'from' => $monday->toDateString(),
            'to' => $monday->addDays(6)->toDateString(),
            'dimension' => 'date',
        ];

        $daily = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/insights/sales?'.http_build_query($range + ['granularity' => 'day']))
            ->assertOk()->json('totals.net_sales');

        $weekly = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/insights/sales?'.http_build_query($range + ['granularity' => 'week']))
            ->assertOk()->json('totals.net_sales');

        $this->assertSame(200, $daily);
        $this->assertSame($daily, $weekly);
    }

    public function test_cancelled_sales_never_enter_the_aggregation(): void
    {
        $sale = $this->sellProduct($this->vetMember, price: 100);
        $sale->update(['status' => SaleStatus::CANCELLED->value]);

        $response = $this->actingAs($this->owner, 'sanctum')->getJson(
            '/api/professional/insights/sales?'.http_build_query([
                'from' => now()->subDay()->toDateString(),
                'to' => now()->addDay()->toDateString(),
                'dimension' => 'date',
            ])
        )->assertOk();

        $response->assertJsonPath('totals.sale_count', 0)
            ->assertJsonPath('totals.net_sales', 0);
    }
}
