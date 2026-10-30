<?php

namespace Tests\Feature\Stock;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Stock\Concerns\BuildsStockFixtures;
use Tests\TestCase;

/**
 * Custo de aquisição real na compra — docs/gap-petflow/PLANO.md, 1.2 (golden `compra-01` em
 * `tests/Fixtures/golden/purchase-cost.json`): frete/seguro/outras/IPI/ST entram no custo, o
 * rateio é pelo maior resto, e a caixa da nota entra no estoque como unidades.
 */
class PurchaseLandedCostTest extends TestCase
{
    use BuildsStockFixtures;
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildStockClinic();
        $this->supplier = Supplier::create([
            'organization_id' => $this->clinic->id,
            'professional_id' => $this->owner->id,
            'legal_name' => 'Atacado Vet Campinas Ltda',
        ]);
        $this->actingAs($this->owner, 'sanctum');
    }

    public function test_manual_golden_compra_01_allocates_header_freight_and_enters_stock_units_at_landed_cost(): void
    {
        [$productA, $productB] = [$this->product(['name' => 'Petisco A']), $this->product(['name' => 'Sachê B'])];

        $purchaseId = $this->postJson('/api/professional/purchases', $this->compra01Payload($productA, $productB))
            ->assertCreated()
            ->assertJsonPath('data.total', 525)
            ->assertJsonPath('data.total_products', 500)
            ->assertJsonPath('data.total_freight', 25)
            ->assertJsonPath('data.total_insurance', '0.00')
            ->assertJsonPath('data.items.0.freight_share', '10.00')
            ->assertJsonPath('data.items.0.landed_total_cost', '210.00')
            ->assertJsonPath('data.items.0.landed_unit_cost', '21.0000')
            ->assertJsonPath('data.items.0.conversion_factor', '1.000')
            ->assertJsonPath('data.items.1.freight_share', '15.00')
            ->assertJsonPath('data.items.1.landed_total_cost', '315.00')
            ->assertJsonPath('data.items.1.landed_unit_cost', '5.2500')
            ->assertJsonPath('data.items.1.conversion_factor', '12.000')
            ->assertJsonPath('data.items.1.purchase_unit', 'CX')
            ->assertJsonPath('data.items.1.stock_quantity', '60.0000')
            ->json('data.id');

        $this->postJson("/api/professional/purchases/{$purchaseId}/receive")->assertOk();

        $this->assertStock($productA, '10.0000', '210.00', '21.00');
        $this->assertStock($productB, '60.0000', '315.00', '5.25');
        $movement = StockMovement::where('product_id', $productB->id)->where('type', 'purchase_in')->sole();
        $this->assertSame('60.0000', $movement->quantity);
        $this->assertSame('315.00', $movement->total_cost);
        $this->assertLedgerMatches($productB);
    }

    public function test_average_cost_blends_with_landed_cost_and_cancel_reverses_the_stock_units(): void
    {
        $product = $this->product(['average_cost' => 20]);
        app(StockService::class)->in($product, StockMovementType::OPENING_BALANCE, 10, ['unit_cost' => 20]);

        // 2 caixas × 60,00 + frete 6,00 = 126,00 por 24 un (5,25/un).
        $purchaseId = $this->postJson('/api/professional/purchases', [
            'supplier_id' => $this->supplier->id,
            'total_freight' => '6.00',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => '60.00', 'conversion_factor' => 12]],
        ])->assertCreated()->assertJsonPath('data.items.0.landed_unit_cost', '5.2500')->json('data.id');
        $this->postJson("/api/professional/purchases/{$purchaseId}/receive")->assertOk();

        // (200,00 + 126,00) / 34 = 9,588… → 9,59.
        $this->assertStock($product, '34.0000', '326.00', '9.59');

        $this->postJson("/api/professional/purchases/{$purchaseId}/cancel", ['reason' => 'Nota errada'])->assertOk();
        $this->assertStock($product, '10.0000', '200.00', '20.00');
        $this->assertLedgerMatches($product);
    }

    public function test_header_freight_is_allocated_by_largest_remainder(): void
    {
        $product = $this->product();
        $item = ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '10.00'];

        $this->postJson('/api/professional/purchases', [
            'supplier_id' => $this->supplier->id,
            'total_freight' => '10.00',
            'items' => [$item, $item, $item],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.freight_share', '3.34')
            ->assertJsonPath('data.items.1.freight_share', '3.33')
            ->assertJsonPath('data.items.2.freight_share', '3.33')
            ->assertJsonPath('data.total', 40);
    }

    public function test_cost_preview_returns_exactly_what_the_purchase_saves(): void
    {
        [$productA, $productB] = [$this->product(), $this->product()];
        $payload = $this->compra01Payload($productA, $productB);
        $payload['total_insurance'] = '1.00';
        $payload['items'][0] += ['ipi_amount' => '2.50', 'icms_st_amount' => '1.10', 'discount' => '3.00'];

        $preview = $this->postJson('/api/professional/purchases/cost-preview', $payload)
            ->assertOk()
            ->assertJsonPath('data.items.1.stock_quantity', '60.0000')
            ->json('data');
        $saved = $this->postJson('/api/professional/purchases', $payload)->assertCreated()->json('data');

        $fields = ['freight_share', 'insurance_share', 'other_share', 'ipi_amount', 'icms_st_amount', 'landed_total_cost', 'landed_unit_cost', 'stock_quantity'];
        foreach ([0, 1] as $index) {
            foreach ($fields as $field) {
                $this->assertSame($preview['items'][$index][$field], $saved['items'][$index][$field], "items.{$index}.{$field}");
            }
        }

        foreach (['total_insurance', 'total_ipi', 'total_icms_st'] as $field) {
            $this->assertSame($preview['totals'][$field], $saved[$field], $field);
        }
        $this->assertEquals((float) $preview['totals']['total'], $saved['total']);
    }

    public function test_cost_preview_example_body_for_compra_01(): void
    {
        [$productA, $productB] = [$this->product(), $this->product()];

        $this->postJson('/api/professional/purchases/cost-preview', $this->compra01Payload($productA, $productB))
            ->assertOk()
            ->assertExactJson(['data' => [
                'items' => [
                    $this->previewLine('200.00', '10.00', '210.00', '21.0000', '1.000', '10.0000'),
                    $this->previewLine('300.00', '15.00', '315.00', '5.2500', '12.000', '60.0000'),
                ],
                'totals' => [
                    'total_products' => '500.00', 'total_discount' => '0.00', 'total_freight' => '25.00',
                    'total_insurance' => '0.00', 'total_other' => '0.00', 'total_ipi' => '0.00',
                    'total_icms_st' => '0.00', 'total' => '525.00',
                ],
            ]]);
    }

    public function test_header_total_that_differs_from_explicit_item_shares_is_refused(): void
    {
        $product = $this->product();
        $payload = [
            'supplier_id' => $this->supplier->id,
            'total_freight' => '25.00',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '10.00', 'freight_share' => '10.00'],
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '10.00', 'freight_share' => '10.00'],
            ],
        ];

        $this->postJson('/api/professional/purchases/cost-preview', $payload)->assertStatus(422)->assertJsonValidationErrors('total_freight');
        $this->postJson('/api/professional/purchases', $payload)->assertStatus(422)->assertJsonValidationErrors('total_freight');

        $payload['total_freight'] = '20.00';
        $this->postJson('/api/professional/purchases', $payload)->assertCreated()->assertJsonPath('data.items.1.freight_share', '10.00');
    }

    public function test_money_with_more_than_two_decimals_and_factor_with_more_than_three_are_refused(): void
    {
        $product = $this->product();

        $this->postJson('/api/professional/purchases', [
            'supplier_id' => $this->supplier->id,
            'total_freight' => '1.234',
            'total_insurance' => '0.001',
            'items' => [[
                'product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '10.00',
                'ipi_amount' => '0.555', 'icms_st_amount' => '1.001', 'conversion_factor' => '1.2345',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'total_freight', 'total_insurance', 'items.0.ipi_amount', 'items.0.icms_st_amount', 'items.0.conversion_factor',
        ]);

        $this->postJson('/api/professional/purchases/cost-preview', [
            'items' => [['quantity' => 1, 'unit_cost' => '10.00', 'conversion_factor' => 0]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.conversion_factor');
    }

    public function test_suggested_price_is_repriced_from_the_landed_cost(): void
    {
        $withMarkup = $this->product(['markup_percent' => 100]);
        $other = $this->product();

        // Linha 200,00 + frete 10,00 → 21,00/un. Markup 50% do item: 31,50; markup 100% do
        // produto (`repriceForNewCost`): 42,00. Sobre o custo da nota (20,00) daria 30 e 40.
        $this->postJson('/api/professional/purchases', [
            'supplier_id' => $this->supplier->id,
            'total_freight' => '20.00',
            'items' => [
                ['product_id' => $other->id, 'quantity' => 10, 'unit_cost' => '20.00', 'markup_percent' => 50],
                ['product_id' => $withMarkup->id, 'quantity' => 10, 'unit_cost' => '20.00'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.landed_unit_cost', '21.0000')
            ->assertJsonPath('data.items.0.suggested_price', 31.5)
            ->assertJsonPath('data.items.1.suggested_price', 42);
    }

    public function test_cost_preview_suggests_the_price_per_stock_unit_from_the_landed_cost(): void
    {
        [$productA, $productB] = [$this->product(['markup_percent' => 50]), $this->product()];
        $payload = $this->compra01Payload($productA, $productB);
        $payload['items'][1]['markup_percent'] = 100;

        // Golden `compra-01-b-markup-100`: 5,2500/un × 2 = 10,50 (não 120,00 sobre a caixa).
        // A sem markup no item cai no markup do produto (50%): 21,00 × 1,5 = 31,50.
        $this->postJson('/api/professional/purchases/cost-preview', $payload)
            ->assertOk()
            ->assertJsonPath('data.items.1.suggested_price', '10.50')
            ->assertJsonPath('data.items.0.suggested_price', '31.50');

        unset($payload['items'][0]['product_id']);
        $this->postJson('/api/professional/purchases/cost-preview', $payload)
            ->assertOk()
            ->assertJsonPath('data.items.0.suggested_price', null);

        $saved = $this->postJson('/api/professional/purchases', $this->compra01Payload($productA, $productB))->json('data');
        $this->assertEquals(31.5, $saved['items'][0]['suggested_price']);
    }

    public function test_xml_preview_suggests_the_price_from_the_matched_product_markup(): void
    {
        $this->xmlPreview('nfe-compra-01.xml')->assertJsonPath('items.0.suggested_price', null);

        $supplier = Supplier::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'legal_name' => 'Atacado', 'document' => '98765432000110']);
        $product = $this->product(['markup_percent' => 100]);
        SupplierProduct::remember($supplier->id, 'B-CX12', $product->id, ['conversion_factor' => '12']);

        $this->xmlPreview('nfe-compra-01.xml')
            ->assertJsonPath('items.1.match.product_id', $product->id)
            ->assertJsonPath('items.1.landed_unit_cost', '5.2500')
            ->assertJsonPath('items.1.suggested_price', '10.50');
    }

    public function test_xml_golden_compra_01_allocates_header_only_freight_and_learns_the_conversion_factor(): void
    {
        $preview = $this->xmlPreview('nfe-compra-01.xml')
            ->assertJsonPath('invoice.total_insurance', '0.00')
            ->assertJsonPath('invoice.total_ipi', '0.00')
            ->assertJsonPath('items.0.freight_share', '10.00')
            ->assertJsonPath('items.0.landed_unit_cost', '21.0000')
            ->assertJsonPath('items.1.freight_share', '15.00')
            ->assertJsonPath('items.1.conversion_factor', '1.000')
            ->assertJsonPath('items.1.purchase_unit', 'CX')
            ->assertJsonPath('items.1.landed_total_cost', '315.00')
            ->assertJsonPath('items.1.landed_unit_cost', '63.0000')
            ->json();

        $payload = $this->confirmPayload($preview);
        $payload['items'][1]['conversion_factor'] = '12';
        $this->postJson('/api/professional/purchases', $payload)
            ->assertCreated()
            ->assertJsonPath('data.total', 525)
            ->assertJsonPath('data.items.1.landed_unit_cost', '5.2500');

        $this->assertSame('12.000', SupplierProduct::where('supplier_product_code', 'B-CX12')->sole()->conversion_factor);

        // Próxima nota do mesmo fornecedor: a caixa já entra como 12 unidades.
        $this->xmlPreview('nfe-compra-01.xml')
            ->assertJsonPath('items.1.match.method', 'supplier_code')
            ->assertJsonPath('items.1.conversion_factor', '12.000')
            ->assertJsonPath('items.1.stock_quantity', '60.0000')
            ->assertJsonPath('items.1.landed_unit_cost', '5.2500');
    }

    public function test_xml_with_per_item_freight_discount_ipi_and_st_uses_the_item_values(): void
    {
        $preview = $this->xmlPreview('nfe-custo-por-item.xml')
            ->assertJsonPath('invoice.total_ipi', '6.00')
            ->assertJsonPath('invoice.total_icms_st', '3.20')
            ->assertJsonPath('invoice.total_other', '0.50')
            // 100,00 − 4,00 + 6,00 + 1,00 + 0,50 + 5,00 (IPI) + 3,20 (ST) = 111,70 → 55,85/un.
            ->assertJsonPath('items.0.discount', '4.00')
            ->assertJsonPath('items.0.freight_share', '6.00')
            ->assertJsonPath('items.0.insurance_share', '1.00')
            ->assertJsonPath('items.0.other_share', '0.50')
            ->assertJsonPath('items.0.ipi_amount', '5.00')
            ->assertJsonPath('items.0.icms_st_amount', '3.20')
            ->assertJsonPath('items.0.landed_total_cost', '111.70')
            ->assertJsonPath('items.0.landed_unit_cost', '55.8500')
            // Item sem vSeg/vOutro/ST na nota: zero, não rateio do total.
            ->assertJsonPath('items.1.freight_share', '2.00')
            ->assertJsonPath('items.1.insurance_share', '0.00')
            ->assertJsonPath('items.1.icms_st_amount', '0.00')
            ->assertJsonPath('items.1.landed_total_cost', '43.00')
            ->assertJsonPath('items.1.landed_unit_cost', '10.7500')
            ->json();

        $this->postJson('/api/professional/purchases', $this->confirmPayload($preview))
            ->assertCreated()
            ->assertJsonPath('data.total', 154.7)
            ->assertJsonPath('data.total_discount', 4)
            ->assertJsonPath('data.total_ipi', '6.00')
            ->assertJsonPath('data.total_icms_st', '3.20')
            ->assertJsonPath('data.items.0.landed_unit_cost', '55.8500');
    }

    /** @return array<string, mixed> */
    private function compra01Payload(Product $productA, Product $productB): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'total_freight' => '25.00',
            'items' => [
                ['product_id' => $productA->id, 'quantity' => 10, 'unit_cost' => '20.00'],
                ['product_id' => $productB->id, 'quantity' => 5, 'unit_cost' => '60.00', 'conversion_factor' => '12', 'purchase_unit' => 'CX'],
            ],
        ];
    }

    /** @return array<string, string|null> */
    private function previewLine(string $gross, string $freight, string $landedTotal, string $landedUnit, string $factor, string $stockQuantity): array
    {
        return [
            'gross_total' => $gross, 'discount' => '0.00', 'total_cost' => $gross,
            'freight_share' => $freight, 'insurance_share' => '0.00', 'other_share' => '0.00',
            'ipi_amount' => '0.00', 'icms_st_amount' => '0.00', 'conversion_factor' => $factor,
            'stock_quantity' => $stockQuantity, 'landed_total_cost' => $landedTotal, 'landed_unit_cost' => $landedUnit,
            // Produtos sem markup e item sem markup: nenhuma sugestão.
            'suggested_price' => null,
        ];
    }

    private function xmlPreview(string $fixture): \Illuminate\Testing\TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('nfe.xml', (string) file_get_contents(base_path("tests/Fixtures/nfe/{$fixture}")));

        return $this->post('/api/professional/purchases/xml-preview', ['xml' => $file], ['Accept' => 'application/json'])->assertOk();
    }

    /**
     * O que a tela de conferência manda: cabeçalho da nota e itens com os valores da prévia.
     *
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function confirmPayload(array $preview): array
    {
        $keys = [
            'supplier_product_code', 'description_on_invoice', 'quantity', 'unit', 'unit_cost', 'gross_total',
            'discount', 'freight_share', 'insurance_share', 'other_share', 'ipi_amount', 'icms_st_amount',
            'conversion_factor', 'purchase_unit',
        ];
        $invoice = $preview['invoice'];

        return [
            'supplier_id' => $preview['supplier']['id'],
            'supplier' => $preview['supplier']['data'],
            'invoice_key' => $invoice['key'],
            'xml_token' => $preview['xml_token'],
            'total_freight' => (string) $invoice['total_freight'],
            'total_insurance' => $invoice['total_insurance'],
            'total_other' => $invoice['total_other'],
            'total_discount' => (string) $invoice['total_discount'],
            'items' => array_map(fn (array $item): array => collect($item)->only($keys)->all()
                + ['new_product' => ['name' => $item['description_on_invoice']]], $preview['items']),
        ];
    }

    private function assertStock(Product $product, string $quantity, string $value, string $averageCost): void
    {
        $product->refresh();
        $this->assertSame($quantity, $product->stock_quantity);
        $this->assertSame($value, (string) $product->stock_value);
        $this->assertSame($averageCost, (string) $product->average_cost);
    }
}
