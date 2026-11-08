<?php

namespace Tests\Feature\Insights;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Insights\Concerns\BuildsInsightsFixtures;
use Tests\TestCase;

/**
 * Regra de negócio 6 da spec: nenhum indicador roda mais que um número fixo de queries —
 * mesmo padrão de `DayAgendaTest` (`DB::enableQueryLog()`), sem N+1 por linha do resultado.
 *
 * Medido pelo CRESCIMENTO: o volume de vendas triplica entre as duas medições e o número de
 * queries tem que ser o mesmo. Um teto absoluto quebrava a cada query fixa nova (cache de
 * permissões, escopo da organização) sem que nada passasse a rodar por linha. A primeira
 * chamada só aquece o que é carregado uma vez por processo (cache de permissões do Spatie).
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
        $url = '/api/professional/insights/sales?'.http_build_query([
            'from' => now()->subMonth()->toDateString(),
            'to' => now()->toDateString(),
            'dimension' => 'date',
        ]);

        $this->sellOnDistinctDays(count: 3, offset: 0);
        $this->countQueriesFor($url);
        $fewRowsQueries = $this->countQueriesFor($url);

        $this->sellOnDistinctDays(count: 6, offset: 3);
        $manyRowsQueries = $this->countQueriesFor($url);

        $this->assertSame($fewRowsQueries, $manyRowsQueries, "Série: {$fewRowsQueries} queries com 3 dias, {$manyRowsQueries} com 9 — indício de N+1.");
    }

    public function test_the_drill_down_endpoint_runs_a_fixed_number_of_queries_regardless_of_row_count(): void
    {
        $url = '/api/professional/insights/sales/drill-down?'.http_build_query([
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
            'dimension' => 'employee',
            'bucket' => $this->vetMember->id,
        ]);

        $this->sellToday(count: 2);
        $this->countQueriesFor($url);
        $fewRowsQueries = $this->countQueriesFor($url);

        $this->sellToday(count: 4);
        $manyRowsQueries = $this->countQueriesFor($url);

        $this->assertSame($fewRowsQueries, $manyRowsQueries, "Drill-down: {$fewRowsQueries} queries com 2 vendas, {$manyRowsQueries} com 6 — indício de N+1.");
    }

    private function sellOnDistinctDays(int $count, int $offset): void
    {
        for ($i = $offset; $i < $offset + $count; $i++) {
            $this->sellProduct($this->vetMember, price: 50 + $i, soldAt: CarbonImmutable::now()->subDays($i));
        }
    }

    private function sellToday(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->sellProduct($this->vetMember, price: 50 + $i);
        }
    }

    private function countQueriesFor(string $url): int
    {
        $this->actingAs($this->owner, 'sanctum');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queryCount;
    }
}
