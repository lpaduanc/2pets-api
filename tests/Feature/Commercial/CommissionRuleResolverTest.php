<?php

namespace Tests\Feature\Commercial;

use App\Models\ProductGroup;
use App\Services\Commercial\CommissionRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCommissionFixtures;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Critério de aceite do docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md,
 * regra de negócio 2: item específico > grupo > funcionário (escopo `all` do próprio staff) >
 * geral (escopo `all` sem staff).
 */
class CommissionRuleResolverTest extends TestCase
{
    use BuildsCommissionFixtures;
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_a_product_specific_rule_wins_over_group_which_wins_over_a_staff_wide_rule(): void
    {
        $group = ProductGroup::create(['organization_id' => $this->clinic->id, 'name' => 'Ração']);
        $product = $this->makeProduct(['product_group_id' => $group->id]);

        $this->makeCommissionRule(['scope' => 'all', 'staff_id' => null, 'percent' => 5]);
        $this->makeCommissionRule(['scope' => 'all', 'staff_id' => $this->groomerMember->id, 'percent' => 8]);
        $groupRule = $this->makeCommissionRule(['scope' => 'product_group', 'scope_id' => $group->id, 'staff_id' => null, 'percent' => 6]);
        $productRule = $this->makeCommissionRule(['scope' => 'product', 'scope_id' => $product->id, 'staff_id' => null, 'percent' => 3]);

        $sale = $this->paySale([[
            'sellable_type' => 'product', 'sellable_id' => $product->id, 'staff_id' => $this->groomerMember->id,
        ]]);
        $item = $sale->items->first();

        $resolver = app(CommissionRuleResolver::class);

        $this->assertSame($productRule->id, $resolver->resolve($item)->id);

        $productRule->delete();
        $this->assertSame($groupRule->id, $resolver->resolve($item)->id);
    }

    public function test_a_staff_specific_general_rule_wins_over_the_organization_wide_rule(): void
    {
        $product = $this->makeProduct();

        $generalRule = $this->makeCommissionRule(['scope' => 'all', 'staff_id' => null, 'percent' => 5]);
        $staffRule = $this->makeCommissionRule(['scope' => 'all', 'staff_id' => $this->groomerMember->id, 'percent' => 8]);

        $sale = $this->paySale([[
            'sellable_type' => 'product', 'sellable_id' => $product->id, 'staff_id' => $this->groomerMember->id,
        ]]);
        $item = $sale->items->first();

        $resolver = app(CommissionRuleResolver::class);
        $this->assertSame($staffRule->id, $resolver->resolve($item)->id);

        $staffRule->delete();
        $this->assertSame($generalRule->id, $resolver->resolve($item)->id);
    }

    public function test_no_matching_rule_falls_back_to_null_for_the_items_own_commission_percent(): void
    {
        $product = $this->makeProduct();

        $sale = $this->paySale([[
            'sellable_type' => 'product', 'sellable_id' => $product->id, 'staff_id' => $this->groomerMember->id,
        ]]);
        $item = $sale->items->first();

        $resolver = app(CommissionRuleResolver::class);
        $this->assertNull($resolver->resolve($item));
    }
}
