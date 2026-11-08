<?php

namespace Tests\Feature\Fiscal;

use App\Models\FiscalDocument;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fiscal\Concerns\BuildsFiscalScenarios;
use Tests\TestCase;

/**
 * NF-e de entrada da devolução de venda — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md
 * §3.5 (golden `fiscal-devolucao-01` e `-02` pelo caminho real).
 */
class SaleReturnFiscalDocumentTest extends TestCase
{
    use BuildsFiscalScenarios;
    use RefreshDatabase;

    private Product $racao;

    private Product $coleira;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        $this->identifyTutor();
        $this->clinic->forceFill(['tax_regime' => 'lucro_presumido'])->save();
        $profile = $this->makeTaxProfile(['name' => 'Presumido 18%', 'icms_csosn' => null, 'icms_cst' => '00', 'icms_rate' => '18']);
        $this->racao = $this->makeProduct(['name' => 'Ração', 'price' => 50, 'tax_profile_id' => $profile->id]);
        $this->coleira = $this->makeProduct(['name' => 'Coleira', 'price' => 30, 'tax_profile_id' => $profile->id]);
    }

    public function test_golden_return_01_proportional_mirrors_the_original_nfce_and_references_it(): void
    {
        $this->clinic->forceFill(['card_surcharge_return_policy' => 'proportional'])->save();
        [$saleId, $original] = $this->issuedGoldenSale();
        $returnId = $this->returnSaleItems($saleId, [[$this->itemId($saleId, $this->racao), 1]]);

        $document = $this->issueReturn($returnId)->assertCreated()->json('data');
        $item = $document['items'][0];

        $this->assertSame(['nfe', 'sale_return', 'in', $saleId, $returnId], [$document['kind'], $document['operation'], $document['direction'], $document['sale_id'], $document['sale_return_id']]);
        $this->assertSame([$original->id, $original->access_key], [$document['referenced_document_id'], $document['referenced_access_key']]);
        $this->assertSame($original->id, $document['referenced_document']['id']);
        $this->assertSame([1, '1'], [$document['number'], $document['series']]);
        $this->assertEqualsWithDelta(46.0, $document['total'], 0.001);
        $this->assertSame(['1202', '00', '1.0000', '50.00', '5.00', '1.00', '46.00', '46.00', '8.28'], [$item['cfop'], $item['cst'], $item['quantity'], $item['gross_total'], $item['discount_share'], $item['surcharge_share'], $item['total'], $item['icms_base'], $item['icms_amount']]);

        // A devolução não cria nem cumpre obrigação da venda: continua sem pendência e sem reemissão.
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/sales/{$saleId}")->assertJsonPath('data.fiscal_pending', []);
        $this->issueSale($saleId)->assertStatus(422);
    }

    public function test_golden_return_01_keep_policy_leaves_the_surcharge_out(): void
    {
        [$saleId] = $this->issuedGoldenSale();
        $returnId = $this->returnSaleItems($saleId, [[$this->itemId($saleId, $this->racao), 1]]);

        $item = $this->issueReturn($returnId)->assertCreated()->json('data.items.0');

        $this->assertSame(['0.00', '45.00', '45.00', '8.10'], [$item['surcharge_share'], $item['total'], $item['icms_base'], $item['icms_amount']]);
    }

    public function test_golden_return_02_successive_returns_add_up_to_the_original_tax(): void
    {
        $product = $this->makeProduct(['name' => 'Sachê', 'price' => '18.52', 'tax_profile_id' => $this->racao->tax_profile_id]);
        $saleId = $this->paidProductSale([[$product, 3]], ['client_id' => $this->tutor->id]);
        $originalIcms = (string) FiscalDocument::findOrFail($this->issueSale($saleId)->assertCreated()->json('data.0.id'))->items()->value('icms_amount');
        $saleItemId = $this->itemId($saleId, $product);

        $first = $this->issueReturn($this->returnSaleItems($saleId, [[$saleItemId, 1]]))->assertCreated()->json('data.items.0');
        $second = $this->issueReturn($this->returnSaleItems($saleId, [[$saleItemId, 2]]))->assertCreated()->json('data');

        $this->assertSame('10.00', $originalIcms);
        $this->assertSame(['18.52', '3.33'], [$first['total'], $first['icms_amount']]);
        $this->assertSame(['37.04', '6.67'], [$second['items'][0]['total'], $second['items'][0]['icms_amount']]);
        $this->assertSame(2, $second['number']);
    }

    public function test_interstate_client_gets_the_interstate_return_cfop(): void
    {
        $this->identifyTutor('RJ');
        [$saleId] = $this->issuedGoldenSale(nfe: true);
        $returnId = $this->returnSaleItems($saleId, [[$this->itemId($saleId, $this->coleira), 1]]);

        $this->issueReturn($returnId)->assertCreated()->assertJsonPath('data.items.0.cfop', '2202');
    }

    public function test_service_only_return_is_skipped_without_error(): void
    {
        $this->openRegisterFor($this->receptionist, 500);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $this->makeService(['price' => 60])->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->receptionist, 'cash'), 'amount' => 60])->assertCreated();
        $returnId = $this->postJson('/api/professional/sale-returns', ['sale_id' => $saleId, 'reason' => 'Desistiu', 'refund_method' => 'cash', 'items' => [['sale_item_id' => SaleItem::where('sale_id', $saleId)->value('id'), 'quantity' => 1]]])->assertCreated()->json('data.id');

        $this->issueReturn($returnId)->assertOk()->assertJsonPath('skipped_reason', 'service_only')->assertJsonPath('data', null);
        $this->assertSame(0, FiscalDocument::where('operation', 'sale_return')->count());
    }

    public function test_anonymous_or_incomplete_client_and_unissued_sale_are_refused(): void
    {
        $anonymous = $this->paidProductSale([[$this->racao, 1]]);
        $this->issueSale($anonymous)->assertCreated();
        $this->issueReturn($this->returnSaleItems($anonymous, [[$this->itemId($anonymous, $this->racao), 1]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Venda sem cliente identificado: informe o cliente com CPF e endereço para emitir a NF-e de devolução.');

        $notIssued = $this->paidProductSale([[$this->racao, 1]], ['client_id' => $this->tutor->id]);
        $this->issueReturn($this->returnSaleItems($notIssued, [[$this->itemId($notIssued, $this->racao), 1]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'A venda não tem NFC-e/NF-e de produto autorizada: emita (ou transmita) o documento da venda antes da devolução.');

        $this->tutor->forceFill(['zip_code' => null, 'cpf' => null])->save();
        $issued = $this->paidProductSale([[$this->racao, 1]], ['client_id' => $this->tutor->id]);
        $this->issueSale($issued)->assertCreated();
        $this->issueReturn($this->returnSaleItems($issued, [[$this->itemId($issued, $this->racao), 1]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Complete o cadastro do cliente para a NF-e de devolução: CPF/CNPJ, CEP.');
    }

    public function test_the_same_return_cannot_be_issued_twice_and_receptionist_cannot_issue(): void
    {
        [$saleId] = $this->issuedGoldenSale();
        $returnId = $this->returnSaleItems($saleId, [[$this->itemId($saleId, $this->racao), 1]]);

        $this->actingAs($this->receptionist, 'sanctum')->postJson("/api/professional/sale-returns/{$returnId}/fiscal-documents")->assertForbidden();
        $this->issueReturn($returnId)->assertCreated();
        $this->issueReturn($returnId)->assertStatus(422);
    }

    /** @return array{0: int, 1: FiscalDocument} venda do golden item-01 (acréscimo 2,60) e o documento dela */
    private function issuedGoldenSale(bool $nfe = false): array
    {
        $saleId = $this->paidProductSale([[$this->racao, 2], [$this->coleira, 1]], ['discount' => '13.00', 'client_id' => $this->tutor->id, 'operation' => $nfe ? 'delivery_resale' : 'in_person_consumer']);
        // Acréscimo injetado: a conta dele (PLANO 1.1) é de CardSurchargeSettingsTest.
        Sale::whereKey($saleId)->update(['surcharge_amount' => '2.60', 'total' => '119.60']);

        return [$saleId, FiscalDocument::findOrFail($this->issueSale($saleId)->assertCreated()->json('data.0.id'))];
    }

    private function itemId(int $saleId, Product $product): int
    {
        return (int) SaleItem::where('sale_id', $saleId)->where('sellable_id', $product->id)->where('sellable_type', Product::class)->value('id');
    }

    private function issueReturn(int $returnId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sale-returns/{$returnId}/fiscal-documents");
    }
}
