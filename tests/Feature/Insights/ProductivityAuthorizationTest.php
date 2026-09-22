<?php

namespace Tests\Feature\Insights;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Insights\Concerns\BuildsInsightsFixtures;
use Tests\TestCase;

/**
 * Regra de negócio 5 da spec: "colaborador nunca vê produtividade de outro" — 403, não uma
 * lista vazia (que esconderia o vazamento em vez de recusar).
 */
class ProductivityAuthorizationTest extends TestCase
{
    use BuildsInsightsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_a_vet_employee_cannot_query_another_employees_productivity(): void
    {
        $this->actingAs($this->vet, 'sanctum')
            ->getJson('/api/professional/insights/productivity?'.http_build_query([
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
                'employee_id' => [$this->ownerMember->id],
            ]))
            ->assertForbidden();
    }

    public function test_a_vet_employee_can_query_their_own_productivity(): void
    {
        $this->sellProduct($this->vetMember, price: 100);

        $this->actingAs($this->vet, 'sanctum')
            ->getJson('/api/professional/insights/productivity?'.http_build_query([
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
                'employee_id' => [$this->vetMember->id],
            ]))
            ->assertOk()
            ->assertJsonPath('totals.sale_count', 1);
    }

    public function test_the_owner_can_query_any_employees_productivity(): void
    {
        $this->sellProduct($this->vetMember, price: 100);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/insights/productivity?'.http_build_query([
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
                'employee_id' => [$this->vetMember->id],
            ]))
            ->assertOk()
            ->assertJsonPath('totals.sale_count', 1);
    }

    public function test_a_receptionist_without_bi_permission_is_forbidden_from_the_bi_routes_entirely(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/insights/productivity?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertForbidden();

        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/me/productivity?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertForbidden();
    }

    public function test_me_productivity_never_leaks_another_collaborators_sales(): void
    {
        $this->sellProduct($this->ownerMember, price: 500);

        $this->actingAs($this->vet, 'sanctum')
            ->getJson('/api/professional/me/productivity?'.http_build_query([
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath('totals.sale_count', 0)
            ->assertJsonPath('totals.net_sales', 0);
    }
}
