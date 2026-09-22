<?php

namespace Tests\Feature\Insights;

use App\Enums\DiscountType;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\Brand;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Insights\Concerns\BuildsInsightsFixtures;
use Tests\TestCase;

/**
 * Item 20 do backlog gap-simplesvet, passada final: dimensões `product`/`group`/`brand`
 * (antes "fora de escopo", ver docs/gap-simplesvet/contratos/20-contrato-api.md). Mesmo
 * critério de aceite mais importante da spec — "a soma do drill-down bate com o ponto da
 * série" — provado para as três, mais a exclusão de item de serviço (que não tem grupo/marca).
 */
class SalesDimensionsTest extends TestCase
{
    use BuildsInsightsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_product_dimension_buckets_by_the_product_sold(): void
    {
        $this->sellProduct($this->vetMember, price: 100);
        $this->sellProduct($this->vetMember, price: 50);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson($this->salesUrl(['dimension' => 'product']))
            ->assertOk();

        $response->assertJsonPath('series.0.bucket', $this->product->id)
            ->assertJsonPath('series.0.net_sales', 150)
            ->assertJsonCount(1, 'series');
    }

    public function test_group_dimension_drill_down_sum_matches_the_series_point(): void
    {
        $group = ProductGroup::create(['organization_id' => $this->clinic->id, 'name' => 'Rações']);
        $this->product->update(['product_group_id' => $group->id]);
        $this->sellProduct($this->vetMember, price: 100);
        $this->sellProduct($this->vetMember, price: 40);

        $series = $this->actingAs($this->owner, 'sanctum')
            ->getJson($this->salesUrl(['dimension' => 'group']))
            ->assertOk()
            ->json('series');

        $this->assertSame($group->id, $series[0]['bucket']);
        $this->assertSame(140.0, $series[0]['net_sales']);

        $drillDown = $this->actingAs($this->owner, 'sanctum')
            ->getJson($this->salesUrl(['dimension' => 'group'], drillDown: true, bucket: (string) $group->id))
            ->assertOk();

        $drillDown->assertJsonPath('summary.net_sales', $series[0]['net_sales']);
    }

    public function test_brand_dimension_reports_unassigned_bucket_for_product_without_brand(): void
    {
        $this->product->update(['brand_id' => null]);
        $this->sellProduct($this->vetMember, price: 60);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson($this->salesUrl(['dimension' => 'brand']))
            ->assertOk();

        $response->assertJsonPath('series.0.bucket', null)
            ->assertJsonPath('series.0.net_sales', 60);

        $drillDown = $this->actingAs($this->owner, 'sanctum')
            ->getJson($this->salesUrl(['dimension' => 'brand'], drillDown: true, bucket: 'unassigned'))
            ->assertOk();

        $drillDown->assertJsonPath('summary.net_sales', 60);
    }

    public function test_brand_dimension_never_mixes_service_items_with_product_items(): void
    {
        $brand = Brand::create(['organization_id' => $this->clinic->id, 'name' => 'MarcaX']);
        $this->product->update(['brand_id' => $brand->id]);
        $this->sellProduct($this->vetMember, price: 100);
        $this->sellService(price: 80);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson($this->salesUrl(['dimension' => 'brand']))
            ->assertOk();

        // Só o produto (100) entra — o serviço (80) não tem marca e não pode aparecer
        // misturado num bucket "unassigned" junto de produto sem marca de verdade.
        $response->assertJsonPath('totals.net_sales', 100)
            ->assertJsonCount(1, 'series');
    }

    /** @param  array<string, string>  $params */
    private function salesUrl(array $params, bool $drillDown = false, ?string $bucket = null): string
    {
        $params += [
            'from' => now()->subMonth()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ];

        if ($bucket !== null) {
            $params['bucket'] = $bucket;
        }

        $suffix = $drillDown ? '/drill-down' : '';

        return "/api/professional/insights/sales{$suffix}?".http_build_query($params);
    }

    private function sellService(float $price): Sale
    {
        $service = Service::create([
            'professional_id' => $this->owner->id,
            'organization_id' => $this->clinic->id,
            'name' => 'Banho',
            'category' => 'grooming',
            'duration' => 30,
            'price' => $price,
            'active' => true,
        ]);

        $sale = Sale::create([
            'organization_id' => $this->clinic->id,
            'professional_id' => $this->owner->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'kind' => SaleKind::SALE->value,
            'status' => SaleStatus::PAID->value,
            'discount_type' => DiscountType::NONE->value,
            'discount_value' => 0,
            'discount_amount' => 0,
            'subtotal' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'created_by' => $this->owner->id,
            'sold_at' => now(),
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'sellable_type' => Service::class,
            'sellable_id' => $service->id,
            'description' => $service->name,
            'staff_id' => $this->vetMember->id,
            'quantity' => 1,
            'unit_price' => $price,
            'unit_cost' => 0,
            'discount' => 0,
            'total' => $price,
        ]);

        $sale->recalculateTotals();
        $sale->paid_amount = $sale->total;
        $sale->save();

        return $sale->fresh();
    }
}
