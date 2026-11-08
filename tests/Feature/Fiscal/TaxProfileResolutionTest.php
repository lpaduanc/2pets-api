<?php

namespace Tests\Feature\Fiscal;

use App\Models\FiscalDocument;
use App\Models\Organization;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fiscal\Concerns\BuildsFiscalScenarios;
use Tests\TestCase;

/**
 * Perfil efetivo: produto → grupo → grupos pais → padrão (spec fase4-fiscal §3.3), no cadastro
 * (resource) e na emissão (snapshot do item).
 */
class TaxProfileResolutionTest extends TestCase
{
    use BuildsFiscalScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_product_resource_shows_the_effective_profile_and_where_it_came_from(): void
    {
        $rootProfile = $this->makeTaxProfile(['name' => 'Rações']);
        $root = $this->group('Alimentos', ['tax_profile_id' => $rootProfile->id]);
        $child = $this->group('Ração seca', ['parent_id' => $root->id]);
        $own = $this->makeTaxProfile(['name' => 'Próprio']);

        $inherited = $this->makeProduct(['product_group_id' => $child->id]);
        $overridden = $this->makeProduct(['product_group_id' => $child->id, 'tax_profile_id' => $own->id]);
        $loose = $this->makeProduct();

        $this->actingAs($this->owner, 'sanctum');
        $this->getJson("/api/professional/products/{$inherited->id}")->assertOk()
            ->assertJsonPath('data.tax_profile_id', null)
            ->assertJsonPath('data.effective_tax_profile', ['id' => $rootProfile->id, 'name' => 'Rações', 'source' => 'parent_group']);
        $this->getJson("/api/professional/products/{$overridden->id}")->assertJsonPath('data.effective_tax_profile.source', 'product');
        $this->getJson("/api/professional/products/{$loose->id}")->assertJsonPath('data.effective_tax_profile', ['id' => $this->defaultTaxProfile->id, 'name' => 'Simples — revenda', 'source' => 'default']);

        $listed = collect($this->getJson('/api/professional/products')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('parent_group', $listed[$inherited->id]['effective_tax_profile']['source']);
    }

    public function test_group_listing_resolves_children_through_the_parent(): void
    {
        $rootProfile = $this->makeTaxProfile(['name' => 'Rações']);
        $root = $this->group('Alimentos', ['tax_profile_id' => $rootProfile->id]);
        $this->group('Ração seca', ['parent_id' => $root->id]);

        $groups = $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/product-groups')->assertOk()->json('data');

        $this->assertSame(['id' => $rootProfile->id, 'name' => 'Rações', 'source' => 'group'], $groups[0]['effective_tax_profile']);
        $this->assertSame('parent_group', $groups[0]['children'][0]['effective_tax_profile']['source']);
    }

    public function test_products_and_groups_accept_only_visible_active_profiles(): void
    {
        $foreign = $this->makeTaxProfile(['name' => 'Alheio'], organization: Organization::factory()->create());
        $inactive = $this->makeTaxProfile(['name' => 'Inativo', 'active' => false]);
        $product = $this->makeProduct();
        $this->actingAs($this->owner, 'sanctum');

        $this->putJson("/api/professional/products/{$product->id}", ['tax_profile_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors('tax_profile_id');
        $this->putJson("/api/professional/products/{$product->id}", ['tax_profile_id' => $inactive->id])->assertStatus(422);
        $this->postJson('/api/professional/product-groups', ['name' => 'G', 'tax_profile_id' => $foreign->id])->assertStatus(422);

        $this->putJson("/api/professional/products/{$product->id}", ['tax_profile_id' => $this->defaultTaxProfile->id])->assertOk()->assertJsonPath('data.tax_profile_id', $this->defaultTaxProfile->id);
    }

    public function test_issuing_freezes_the_inherited_profile_and_its_source(): void
    {
        $rootProfile = $this->makeTaxProfile(['name' => 'Rações'], ['sale_consumer' => ['intrastate' => '5405']]);
        $root = $this->group('Alimentos', ['tax_profile_id' => $rootProfile->id]);
        $child = $this->group('Ração seca', ['parent_id' => $root->id]);
        $saleId = $this->paidProductSale([[$this->makeProduct(['product_group_id' => $child->id]), 1]]);

        $item = FiscalDocument::findOrFail($this->issueSale($saleId)->assertCreated()->json('data.0.id'))->items()->firstOrFail();

        $this->assertSame([$rootProfile->id, 'parent_group', '5405'], [$item->tax_profile_id, $item->profile_source->value, $item->cfop]);
    }

    public function test_an_inactive_group_profile_is_skipped_up_to_the_default(): void
    {
        $inactive = $this->makeTaxProfile(['name' => 'Desligado', 'active' => false]);
        $group = $this->group('Brinquedos', ['tax_profile_id' => $inactive->id]);
        $saleId = $this->paidProductSale([[$this->makeProduct(['product_group_id' => $group->id]), 1]]);

        $item = FiscalDocument::findOrFail($this->issueSale($saleId)->assertCreated()->json('data.0.id'))->items()->firstOrFail();

        $this->assertSame([$this->defaultTaxProfile->id, 'default'], [$item->tax_profile_id, $item->profile_source->value]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function group(string $name, array $attributes = []): ProductGroup
    {
        return ProductGroup::create($attributes + ['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => $name]);
    }
}
