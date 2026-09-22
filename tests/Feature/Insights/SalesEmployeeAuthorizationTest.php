<?php

namespace Tests\Feature\Insights;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Insights\Concerns\BuildsInsightsFixtures;
use Tests\TestCase;

/**
 * Achado do frontend, corrigido nesta passada final: `GET professional/insights/sales` (e
 * `.../drill-down`, `.../export`, que compartilham `InsightQueryRequest::toInsightQuery()`)
 * aceitava `filters[employee_id]` sem passar por `ProductivityAuthorization` — regra de
 * negócio 5 da spec 20 ("colaborador nunca vê produtividade/venda de outro") já valia para
 * `insights/productivity`/`me/productivity` (ver `ProductivityAuthorizationTest`), mas não
 * para o indicador `sales`.
 */
class SalesEmployeeAuthorizationTest extends TestCase
{
    use BuildsInsightsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_employee_with_only_own_scope_never_sees_the_whole_clinic_without_a_filter(): void
    {
        $this->sellProduct($this->vetMember, price: 100);
        $this->sellProduct($this->ownerMember, price: 500);

        $response = $this->actingAs($this->vet, 'sanctum')
            ->getJson($this->salesUrl())
            ->assertOk();

        // Sem NENHUM filtro, o vet só pode ver o próprio vínculo — nunca a organização
        // inteira (600) só porque não mandou `filters.employee_id`.
        $response->assertJsonPath('totals.net_sales', 100);
    }

    public function test_employee_cannot_request_another_employees_sales_explicitly(): void
    {
        $this->sellProduct($this->ownerMember, price: 500);

        $this->actingAs($this->vet, 'sanctum')
            ->getJson($this->salesUrl(['filters' => ['employee_id' => [$this->ownerMember->id]]]))
            ->assertForbidden();
    }

    public function test_employee_cannot_drill_down_into_another_employees_sale(): void
    {
        $this->sellProduct($this->ownerMember, price: 500);

        $this->actingAs($this->vet, 'sanctum')
            ->getJson($this->salesUrl(['filters' => ['employee_id' => [$this->ownerMember->id]]], drillDown: true))
            ->assertForbidden();
    }

    public function test_owner_can_request_any_employees_sales(): void
    {
        $this->sellProduct($this->vetMember, price: 100);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson($this->salesUrl(['filters' => ['employee_id' => [$this->vetMember->id]]]))
            ->assertOk()
            ->assertJsonPath('totals.net_sales', 100);
    }

    /** @param  array<string, mixed>  $params */
    private function salesUrl(array $params = [], bool $drillDown = false): string
    {
        $params += [
            'from' => now()->subMonth()->toDateString(),
            'to' => now()->addDay()->toDateString(),
            'dimension' => 'date',
        ];

        $suffix = $drillDown ? '/drill-down' : '';

        return "/api/professional/insights/sales{$suffix}?".http_build_query($params);
    }
}
