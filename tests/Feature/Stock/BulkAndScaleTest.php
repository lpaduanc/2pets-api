<?php

namespace Tests\Feature\Stock;

use App\Enums\StockMovementType;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Stock\Bulk\ScaleBarcodeParser;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Granel, abertura de embalagem e balança (docs/gap-petflow/PLANO.md, 3.3): abertura (movimentos,
 * lote herdado, custo médio, 422 sem estoque), conversão inválida, leitura da etiqueta no PDV nos
 * dois modos e com dígito inválido, exportação Toledo MGV e outra organização.
 */
class BulkAndScaleTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private Product $bag;

    private Product $bulk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        $this->bag = $this->makeProduct(['name' => 'Ração Premium 15kg', 'price' => 250, 'stock_quantity' => 0, 'track_batches' => true]);
        $this->bulk = $this->makeProduct(['name' => 'Ração Premium granel', 'price' => 32.90, 'stock_quantity' => 0, 'unit_of_sale' => 'KG', 'decimal_places_allowed' => 3, 'track_batches' => true, 'scale_code' => '00123', 'sold_by_weight' => true]);
        $stock = app(StockService::class);
        $stock->in($this->bag, StockMovementType::PURCHASE_IN, '1', ['total_cost' => '180.00', 'batch_code' => 'L-VENCE-ANTES', 'expires_at' => '2027-01-31']);
        $stock->in($this->bag, StockMovementType::PURCHASE_IN, '1', ['total_cost' => '180.00', 'batch_code' => 'L-VENCE-DEPOIS', 'expires_at' => '2027-06-30']);
        $stock->in($this->bulk, StockMovementType::PURCHASE_IN, '3', ['total_cost' => '40.50', 'batch_code' => 'G-ANTIGO', 'expires_at' => '2027-03-31']);
    }

    public function test_opening_moves_the_value_inherits_the_fefo_batch_and_reprices_the_bulk(): void
    {
        $this->conversion()->assertCreated()->assertJsonPath('data.factor', '15.0000');

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/stock/package-openings', ['from_product_id' => $this->bag->id, 'quantity' => 1])
            ->assertCreated()->assertJsonPath('data.entered_quantity', '15.0000')->assertJsonPath('data.total_cost', '180.00');

        // Golden abertura-01: (40,50 + 180,00) / 18 = 12,2500/kg.
        $this->assertSame(['18.0000', '12.2500', '220.50'], [(string) $this->bulk->fresh()->stock_quantity, (string) $this->bulk->fresh()->average_cost, (string) $this->bulk->fresh()->stock_value]);
        $this->assertSame(['1.0000', '180.00'], [(string) $this->bag->fresh()->stock_quantity, (string) $this->bag->fresh()->stock_value]);

        $out = StockMovement::where('type', 'package_open_out')->sole();
        $in = StockMovement::where('type', 'package_open_in')->sole();
        $this->assertSame(['L-VENCE-ANTES', '180.00'], [$out->batch->batch_code, (string) $out->total_cost]);
        $this->assertSame(['L-VENCE-ANTES', '2027-01-31', '15.0000', '12.0000', '180.00'], [$in->batch->batch_code, $in->batch->expires_at->toDateString(), (string) $in->quantity, (string) $in->unit_cost, (string) $in->total_cost]);

        // No kardex, com o rótulo novo.
        $this->getJson("/api/professional/products/{$this->bulk->id}/stock-movements")->assertOk()->assertJsonFragment(['type' => 'package_open_in']);

        // Abrir mais do que há: 422, nada muda.
        $this->postJson('/api/professional/stock/package-openings', ['from_product_id' => $this->bag->id, 'quantity' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(1, StockMovement::where('type', 'package_open_out')->count());
    }

    public function test_invalid_conversions_are_refused(): void
    {
        $unit = $this->makeProduct(['name' => 'Coleira', 'stock_quantity' => 0]);

        $this->conversion(['to_product_id' => $this->bag->id])->assertUnprocessable()->assertJsonValidationErrors('to_product_id');
        $this->conversion(['factor' => 0])->assertUnprocessable()->assertJsonValidationErrors('factor');
        $this->conversion(['to_product_id' => $unit->id])->assertUnprocessable()->assertJsonValidationErrors('to_product_id');
        $this->conversion()->assertCreated();
        $this->conversion()->assertUnprocessable()->assertJsonValidationErrors('to_product_id');

        [$stranger, $foreign] = $this->otherOrganization();
        $this->conversion(['to_product_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('to_product_id');
        // Sem `products.update` (aqui, um tutor) não cadastra conversão.
        $this->actingAs($this->tutor, 'sanctum')->postJson('/api/professional/stock/product-conversions', ['from_product_id' => $this->bag->id, 'to_product_id' => $this->bulk->id, 'factor' => 15])->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson('/api/professional/stock/product-conversions')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($stranger, 'sanctum')->postJson('/api/professional/stock/package-openings', ['from_product_id' => $this->bag->id, 'quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('from_product_id');
    }

    public function test_scale_barcode_in_weight_and_price_modes_and_invalid_check_digit(): void
    {
        $this->openRegisterFor($this->receptionist);
        $saleId = $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');

        // Peso: 1,250 kg × 32,90 = 41,13 (golden balanca-01).
        $this->postJson('/api/professional/pdv/scale-barcode', ['barcode' => '2001230012502'])->assertOk()
            ->assertJsonPath('data.mode', 'weight')->assertJsonPath('data.quantity', '1.250')->assertJsonPath('data.total', '41.13')->assertJsonPath('data.product.id', $this->bulk->id);
        $this->postJson('/api/professional/pdv/scale-barcode', ['barcode' => '2001230012502', 'sale_id' => $saleId])->assertCreated()
            ->assertJsonPath('item.quantity', '1.2500')->assertJsonPath('item.total', 41.13)->assertJsonPath('sale.total', 41.13);

        // Dígito errado: 422.
        $this->postJson('/api/professional/pdv/scale-barcode', ['barcode' => '2001230012503'])->assertUnprocessable()->assertJsonValidationErrors('barcode');

        // Modo preço: 41,10 preservado (golden balanca-03), mesmo que o preço do cadastro não feche.
        $this->actingAs($this->owner, 'sanctum')->putJson('/api/professional/stock/scale-settings', ['prefixes' => ['2'], 'code_length' => 5, 'value_length' => 6, 'mode' => 'price'])->assertOk()->assertJsonPath('data.mode', 'price');
        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/pdv/scale-barcode', ['barcode' => '2001230041106', 'sale_id' => $saleId])->assertCreated()
            ->assertJsonPath('data.quantity', '1.249')->assertJsonPath('data.unit_price', '32.91')->assertJsonPath('item.total', 41.1)->assertJsonPath('sale.total', 82.23);

        // Código sem produto.
        $code = '299999001000';
        $this->postJson('/api/professional/pdv/scale-barcode', ['barcode' => $code.ScaleBarcodeParser::checkDigit($code)])->assertUnprocessable()->assertJsonValidationErrors('barcode');
    }

    public function test_toledo_export_skips_products_without_scale_code_and_other_organizations(): void
    {
        $this->makeProduct(['name' => 'Petisco a granel', 'price' => 49.9, 'unit_of_sale' => 'KG', 'decimal_places_allowed' => 3, 'sold_by_weight' => true, 'stock_quantity' => 0]);
        [, $foreign] = $this->otherOrganization();
        $foreign->update(['scale_code' => '00777', 'sold_by_weight' => true]);

        $response = $this->actingAs($this->owner, 'sanctum')->get('/api/professional/stock/scale-export?format=toledo_mgv')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="ITENSMGV.TXT"');
        $lines = array_values(array_filter(explode("\r\n", $response->getContent())));

        $this->assertCount(1, $lines);
        $this->assertSame(74, strlen($lines[0]));
        $this->assertSame('010000123003290000'.str_pad('RACAO PREMIUM GRANEL', 25).str_repeat(' ', 25).'000000', $lines[0]);
        $this->actingAs($this->owner, 'sanctum')->get('/api/professional/stock/scale-export?format=filizola')->assertStatus(302);
    }

    public function test_scale_code_is_unique_in_the_organization(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/products', ['name' => 'Outro', 'sku' => 'X-1', 'price' => 10, 'scale_code' => '0123'])
            ->assertUnprocessable()->assertJsonValidationErrors('scale_code');
        $this->postJson('/api/professional/products', ['name' => 'Outro', 'sku' => 'X-1', 'price' => 10, 'scale_code' => '00124', 'sold_by_weight' => true])
            ->assertCreated()->assertJsonPath('data.scale_code', '00124')->assertJsonPath('data.sold_by_weight', true);
    }

    /** @param  array<string, mixed>  $overrides */
    private function conversion(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/stock/product-conversions', $overrides + ['from_product_id' => $this->bag->id, 'to_product_id' => $this->bulk->id, 'factor' => 15]);
    }

    /** @return array{0: User, 1: Product} */
    private function otherOrganization(): array
    {
        $other = Organization::factory()->create();
        $stranger = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($other, 'organization')->create(['user_id' => $stranger->id]);

        return [$stranger, $this->makeProduct(['name' => 'Deles', 'unit_of_sale' => 'KG', 'decimal_places_allowed' => 3, 'stock_quantity' => 0], organization: $other, by: $stranger)];
    }
}
