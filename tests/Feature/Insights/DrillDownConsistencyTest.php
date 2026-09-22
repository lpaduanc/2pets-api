<?php

namespace Tests\Feature\Insights;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Insights\Concerns\BuildsInsightsFixtures;
use Tests\TestCase;

/**
 * Critério de aceite mais importante da spec (regra de negócio 2): a soma das linhas do
 * drill-down de um ponto do gráfico bate EXATAMENTE com o valor daquele ponto.
 */
class DrillDownConsistencyTest extends TestCase
{
    use BuildsInsightsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_drill_down_of_an_employee_bucket_sums_exactly_to_the_series_point(): void
    {
        $today = CarbonImmutable::now();

        $this->sellProduct($this->vetMember, price: 100, soldAt: $today);
        $this->sellProduct($this->vetMember, price: 70, soldAt: $today, discount: 5);
        // Ruído: outro colaborador, não pode entrar no bucket do vet.
        $this->sellProduct($this->ownerMember, price: 999, soldAt: $today);

        $range = ['from' => $today->toDateString(), 'to' => $today->toDateString(), 'dimension' => 'employee'];

        $series = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/insights/sales?'.http_build_query($range))
            ->assertOk()
            ->json('series');

        $vetPoint = collect($series)->firstWhere('bucket', $this->vetMember->id);
        $this->assertNotNull($vetPoint);

        $drillDown = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/insights/sales/drill-down?'.http_build_query($range + ['bucket' => $this->vetMember->id]))
            ->assertOk();

        $drillDown->assertJsonPath('summary.net_sales', $vetPoint['net_sales'])
            ->assertJsonCount(2, 'data');

        $sumOfRows = collect($drillDown->json('data'))->sum('net');
        $this->assertSame($vetPoint['net_sales'], round($sumOfRows, 2));
    }

    public function test_drill_down_of_a_weekday_bucket_only_lists_sales_from_that_weekday(): void
    {
        $monday = CarbonImmutable::now()->startOfWeek();
        $wednesday = $monday->addDays(2);

        $this->sellProduct($this->vetMember, price: 40, soldAt: $monday);
        $this->sellProduct($this->vetMember, price: 60, soldAt: $wednesday);

        $range = [
            'from' => $monday->toDateString(),
            'to' => $monday->addDays(6)->toDateString(),
            'dimension' => 'weekday',
        ];

        $drillDown = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/insights/sales/drill-down?'.http_build_query($range + ['bucket' => (string) $monday->isoWeekday()]))
            ->assertOk();

        $drillDown->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.net_sales', 40)
            ->assertJsonPath('data.0.net', 40);
    }
}
