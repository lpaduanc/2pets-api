<?php

namespace Tests\Feature\Insights;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Insights\Concerns\BuildsInsightsFixtures;
use Tests\TestCase;

/**
 * Regra de negócio 6 da spec: nenhum indicador roda mais que um número fixo de queries —
 * mesmo padrão de `DayAgendaTest` (`DB::enableQueryLog()`), sem N+1 por linha do resultado.
 */
class InsightsQueryCountTest extends TestCase
{
    use BuildsInsightsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_the_series_endpoint_runs_a_fixed_number_of_queries_regardless_of_row_count(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->sellProduct($this->vetMember, price: 50 + $i, soldAt: now()->subDays($i));
        }

        $queryCount = $this->countQueriesFor(fn () => $this->getJson(
            '/api/professional/insights/sales?'.http_build_query([
                'from' => now()->subMonth()->toDateString(),
                'to' => now()->toDateString(),
                'dimension' => 'date',
            ])
        )->assertOk());

        // Autenticação Sanctum + `permission:` + 1 query de agregação — nunca uma por dia/venda.
        $this->assertLessThanOrEqual(8, $queryCount, "Série executou {$queryCount} queries — indício de N+1.");
    }

    public function test_the_drill_down_endpoint_runs_a_fixed_number_of_queries_regardless_of_row_count(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->sellProduct($this->vetMember, price: 50 + $i);
        }

        $queryCount = $this->countQueriesFor(fn () => $this->getJson(
            '/api/professional/insights/sales/drill-down?'.http_build_query([
                'from' => now()->toDateString(),
                'to' => now()->toDateString(),
                'dimension' => 'employee',
                'bucket' => $this->vetMember->id,
            ])
        )->assertOk());

        // Summary + total + página (com eager load de cliente/pet/vendedor) — fixo, não por linha.
        $this->assertLessThanOrEqual(10, $queryCount, "Drill-down executou {$queryCount} queries — indício de N+1.");
    }

    private function countQueriesFor(\Closure $action): int
    {
        $this->actingAs($this->owner, 'sanctum');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queryCount;
    }
}
