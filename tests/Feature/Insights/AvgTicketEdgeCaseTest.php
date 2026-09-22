<?php

namespace Tests\Feature\Insights;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Insights\Concerns\BuildsInsightsFixtures;
use Tests\TestCase;

/**
 * Regra de negócio 1 da spec: "ticket médio = líquido ÷ nº de vendas, com `0` explícito (não
 * erro/exception) quando não há vendas no período".
 */
class AvgTicketEdgeCaseTest extends TestCase
{
    use BuildsInsightsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_average_ticket_is_zero_without_division_by_zero_when_there_are_no_sales(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')->getJson(
            '/api/professional/insights/sales?'.http_build_query([
                'from' => now()->subMonth()->toDateString(),
                'to' => now()->subMonth()->toDateString(),
                'dimension' => 'date',
            ])
        );

        $response->assertOk()
            ->assertJsonPath('totals.avg_ticket', 0)
            ->assertJsonPath('totals.sale_count', 0)
            ->assertJsonPath('totals.net_sales', 0)
            ->assertJsonPath('series', []);
    }

    public function test_my_productivity_is_zero_without_division_by_zero_for_a_collaborator_with_no_sales(): void
    {
        $response = $this->actingAs($this->vet, 'sanctum')->getJson(
            '/api/professional/me/productivity?'.http_build_query([
                'from' => now()->subMonth()->toDateString(),
                'to' => now()->subMonth()->toDateString(),
            ])
        );

        $response->assertOk()->assertJsonPath('totals.avg_ticket', 0);
    }
}
