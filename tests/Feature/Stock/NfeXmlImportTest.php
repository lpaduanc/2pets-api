<?php

namespace Tests\Feature\Stock;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Stock\Concerns\BuildsStockFixtures;
use Tests\TestCase;

/** Importação de XML de NF-e — critérios de aceite do doc 06. */
class NfeXmlImportTest extends TestCase
{
    use BuildsStockFixtures;
    use RefreshDatabase;

    private const KEY = '35260912345678000190550010000012341000012345';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildStockClinic();
    }

    public function test_preview_parses_the_invoice_and_matches_by_gtin_without_saving(): void
    {
        $racao = $this->product(['name' => 'Ração Premium Adulto 15kg', 'gtin' => '7891234567895']);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->post('/api/professional/purchases/xml-preview', ['xml' => $this->fixture()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('supplier.id', null)
            ->assertJsonPath('supplier.data.document', '12345678000190')
            ->assertJsonPath('supplier.data.trade_name', 'DistriPet')
            ->assertJsonPath('invoice.number', '1234')
            ->assertJsonPath('invoice.key', self::KEY)
            ->assertJsonPath('invoice.issued_at', '2026-09-18')
            ->assertJsonPath('invoice.total', 615)
            ->assertJsonPath('duplicate_purchase_id', null)
            ->assertJsonCount(2, 'items');

        $response->assertJsonPath('items.0.quantity', 5)
            ->assertJsonPath('items.0.unit_cost', 100)
            ->assertJsonPath('items.0.batch', 'L2026A')
            ->assertJsonPath('items.0.expires_at', '2027-06-01')
            ->assertJsonPath('items.0.match.product_id', $racao->id)
            ->assertJsonPath('items.0.match.method', 'gtin')
            // "SEM GTIN" não é código de barras; item novo exige decisão.
            ->assertJsonPath('items.1.gtin', null)
            ->assertJsonPath('items.1.match', null)
            ->assertJsonPath('items.1.total_cost', 100);

        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, Supplier::count());
        Storage::disk('local')->assertExists("purchase-xml/{$this->owner->id}/".$response->json('xml_token').'.xml');
    }

    public function test_confirming_the_import_creates_the_supplier_and_learns_supplier_codes(): void
    {
        $racao = $this->product(['name' => 'Ração Premium Adulto 15kg', 'gtin' => '7891234567895']);
        $this->actingAs($this->owner, 'sanctum');

        $preview = $this->post('/api/professional/purchases/xml-preview', ['xml' => $this->fixture()], ['Accept' => 'application/json'])->json();

        $payload = [
            'supplier_id' => null,
            'supplier' => $preview['supplier']['data'],
            'invoice_number' => $preview['invoice']['number'],
            'invoice_series' => $preview['invoice']['series'],
            'invoice_key' => $preview['invoice']['key'],
            'invoice_issued_at' => $preview['invoice']['issued_at'],
            'total_freight' => $preview['invoice']['total_freight'],
            'xml_token' => $preview['xml_token'],
            'items' => [
                $this->itemFrom($preview['items'][0]) + ['product_id' => $racao->id],
                $this->itemFrom($preview['items'][1]) + ['new_product' => ['name' => 'Shampoo Neutro 500ml']],
            ],
        ];

        $purchaseId = $this->postJson('/api/professional/purchases', $payload)
            ->assertCreated()
            ->assertJsonPath('data.has_xml', true)
            ->assertJsonPath('data.supplier.document', '12345678000190')
            ->assertJsonPath('data.total', 615)
            ->json('data.id');

        $this->postJson("/api/professional/purchases/{$purchaseId}/receive")->assertOk();

        $supplier = Supplier::sole();
        $this->assertSame('DISTRIBUIDORA PET PAULISTA LTDA', $supplier->legal_name);
        $this->assertTrue(SupplierProduct::where('supplier_product_code', 'SH-500')->exists());

        // Segunda nota do mesmo fornecedor: fornecedor reaproveitado pelo CNPJ, shampoo casado
        // pelo código do fornecedor aprendido, e a nota repetida é acusada.
        $second = $this->post('/api/professional/purchases/xml-preview', ['xml' => $this->fixture()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('supplier.id', $supplier->id)
            ->assertJsonPath('supplier.match', 'document')
            ->assertJsonPath('duplicate_purchase_id', $purchaseId)
            ->assertJsonPath('items.1.match.method', 'supplier_code');

        $this->assertSame(1, Supplier::count());
        $this->postJson('/api/professional/purchases', ['xml_token' => $second->json('xml_token')] + $payload)->assertStatus(422);
    }

    public function test_xml_token_of_another_user_is_rejected_and_non_nfe_xml_is_refused(): void
    {
        $product = $this->product();
        $token = $this->actingAs($this->owner, 'sanctum')
            ->post('/api/professional/purchases/xml-preview', ['xml' => $this->fixture()], ['Accept' => 'application/json'])
            ->json('xml_token');

        $other = \App\Models\User::factory()->professional()->create();
        $this->actingAs($other, 'sanctum')
            ->postJson('/api/professional/purchases', [
                'supplier' => ['legal_name' => 'Outro'],
                'xml_token' => $token,
                'items' => [['new_product' => ['name' => 'X'], 'quantity' => 1, 'unit_cost' => 1]],
            ])->assertStatus(422);

        $bogus = UploadedFile::fake()->createWithContent('nota.xml', '<?xml version="1.0"?><root><a>1</a></root>');
        $this->post('/api/professional/purchases/xml-preview', ['xml' => $bogus], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertNotNull($product);
    }

    private function fixture(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'nfe.xml',
            (string) file_get_contents(base_path('tests/Fixtures/nfe/nfe-distribuidora.xml'))
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function itemFrom(array $item): array
    {
        return collect($item)->only([
            'supplier_product_code', 'description_on_invoice', 'quantity', 'unit', 'unit_cost',
            'discount', 'batch', 'expires_at', 'ncm',
        ])->all();
    }
}
