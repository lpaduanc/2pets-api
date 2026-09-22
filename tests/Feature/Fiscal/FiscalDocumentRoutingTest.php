<?php

namespace Tests\Feature\Fiscal;

use App\Models\FiscalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Roteamento de documento fiscal — critérios de aceite do
 * docs/gap-simplesvet/specs/05-emissao-fiscal-nfe-nfce-nfse-spec.md: venda só de serviço a PF
 * emite NFS-e; só de produto emite NFC-e ou NF-e conforme `fiscal_operation`; mista emite as
 * duas; revenda sempre NF-e, nunca NFC-e, mesmo presencial.
 */
class FiscalDocumentRoutingTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_a_service_only_sale_emits_a_single_nfse(): void
    {
        $saleId = $this->paidSale(services: [60]);

        $documents = $this->issue($saleId);

        $this->assertCount(1, $documents);
        $this->assertSame('nfse', $documents[0]['kind']);
        $this->assertSame('authorized', $documents[0]['status']);
        $this->assertEqualsWithDelta(60.0, $documents[0]['total'], 0.01);
    }

    public function test_a_product_only_in_person_consumer_sale_emits_nfce(): void
    {
        $saleId = $this->paidSale(products: [100]);

        $documents = $this->issue($saleId);

        $this->assertCount(1, $documents);
        $this->assertSame('nfce', $documents[0]['kind']);
    }

    public function test_a_resale_sale_always_emits_nfe_never_nfce_even_in_person(): void
    {
        $saleId = $this->paidSale(products: [100], fiscalOperation: 'in_person_resale');

        $documents = $this->issue($saleId);

        $this->assertCount(1, $documents);
        $this->assertSame('nfe', $documents[0]['kind']);
    }

    public function test_a_mixed_sale_emits_both_documents_split_by_item_type(): void
    {
        $saleId = $this->paidSale(products: [200], services: [50]);

        $documents = $this->issue($saleId);

        $this->assertCount(2, $documents);
        $kinds = collect($documents)->pluck('kind')->sort()->values()->all();
        $this->assertSame(['nfce', 'nfse'], $kinds);

        $nfce = collect($documents)->firstWhere('kind', 'nfce');
        $nfse = collect($documents)->firstWhere('kind', 'nfse');
        $this->assertEqualsWithDelta(200.0, $nfce['total'], 0.01);
        $this->assertEqualsWithDelta(50.0, $nfse['total'], 0.01);
    }

    public function test_issuing_twice_for_the_same_sale_is_rejected(): void
    {
        $saleId = $this->paidSale(services: [60]);
        $this->issue($saleId);

        $this->postJson("/api/professional/sales/{$saleId}/fiscal-documents")->assertStatus(422);
    }

    public function test_cancelling_frees_the_sale_for_a_new_issuance(): void
    {
        $saleId = $this->paidSale(services: [60]);
        $documentId = $this->issue($saleId)[0]['id'];

        $this->postJson("/api/professional/fiscal-documents/{$documentId}/cancel", ['reason' => 'Duplicada'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', FiscalDocument::find($documentId)->status->value);

        $reissued = $this->issue($saleId);
        $this->assertCount(1, $reissued);
        $this->assertSame('authorized', $reissued[0]['status']);
    }

    public function test_a_document_that_is_not_authorized_cannot_be_cancelled_twice(): void
    {
        $saleId = $this->paidSale(services: [60]);
        $documentId = $this->issue($saleId)[0]['id'];

        $this->postJson("/api/professional/fiscal-documents/{$documentId}/cancel", ['reason' => 'Duplicada'])->assertOk();

        $this->postJson("/api/professional/fiscal-documents/{$documentId}/cancel", ['reason' => 'De novo'])
            ->assertStatus(422);
    }

    public function test_only_the_owner_issues_or_cancels_a_fiscal_document(): void
    {
        $product = $this->makeProduct(['price' => 100]);
        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 100])->assertCreated();

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/fiscal-documents")
            ->assertForbidden();
    }

    /**
     * @param  list<float>  $products
     * @param  list<float>  $services
     */
    private function paidSale(array $products = [], array $services = [], string $fiscalOperation = 'in_person_consumer'): int
    {
        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale', 'fiscal_operation' => $fiscalOperation])
            ->assertCreated()->json('data.id');

        $total = 0.0;
        foreach ($products as $price) {
            $product = $this->makeProduct(['price' => $price, 'sku' => 'SKU-'.uniqid()]);
            $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id])->assertCreated();
            $total += $price;
        }
        foreach ($services as $price) {
            $service = $this->makeService(['price' => $price]);
            $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $service->id])->assertCreated();
            $total += $price;
        }

        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => $total])
            ->assertCreated()
            ->assertJsonPath('sale.status', 'paid');

        return $saleId;
    }

    /** @return list<array<string, mixed>> */
    private function issue(int $saleId): array
    {
        return $this->postJson("/api/professional/sales/{$saleId}/fiscal-documents")
            ->assertCreated()
            ->json('data');
    }
}
