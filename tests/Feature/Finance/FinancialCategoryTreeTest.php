<?php

namespace Tests\Feature\Finance;

use App\Models\FinancialCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Plano de contas — critérios de aceite do docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md:
 * "categoria-pai só agrupa" e "só OWNER vê o financeiro".
 */
class FinancialCategoryTreeTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_tree_seeds_the_default_plan_with_groups_and_leaf_children(): void
    {
        $groups = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/financial-categories/tree')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($groups);
        $this->assertTrue(collect($groups)->every(fn (array $g) => $g['is_group'] === true));
        $revenueGroup = collect($groups)->firstWhere('name', 'Receita de vendas');
        $this->assertNotNull($revenueGroup);
        $this->assertSame(['Produtos', 'Serviços'], collect($revenueGroup['children'])->pluck('name')->all());
    }

    public function test_receptionist_cannot_view_the_financial_category_tree(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/financial-categories/tree')
            ->assertForbidden();
    }

    public function test_a_leaf_category_cannot_be_used_as_a_parent(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/financial-categories/tree');

        $leaf = FinancialCategory::where('organization_id', $this->clinic->id)->where('kind', 'entry')->first();

        $this->postJson('/api/professional/financial-categories', [
            'name' => 'Sub-categoria inválida', 'nature' => 'revenue', 'parent_id' => $leaf->id,
        ])->assertStatus(422);
    }

    public function test_owner_creates_a_group_and_a_leaf_under_it(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/financial-categories/tree');

        $groupId = $this->postJson('/api/professional/financial-categories', [
            'name' => 'Marketing', 'nature' => 'expense', 'kind' => 'group',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/professional/financial-categories', [
            'name' => 'Anúncios online', 'nature' => 'expense', 'kind' => 'entry', 'parent_id' => $groupId,
        ])->assertCreated()->assertJsonPath('data.parent_id', $groupId);
    }
}
