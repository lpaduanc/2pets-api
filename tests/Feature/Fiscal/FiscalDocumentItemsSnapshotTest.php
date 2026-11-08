<?php

namespace Tests\Feature\Fiscal;

use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Models\TaxProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fiscal\Concerns\BuildsFiscalScenarios;
use Tests\TestCase;

/**
 * Itens congelados do documento de venda — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md
 * §3.1/§3.2, golden `fiscal-item-01` pelo caminho real (API): o mesmo número da conta pura.
 */
class FiscalDocumentItemsSnapshotTest extends TestCase
{
    use BuildsFiscalScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_golden_item_01_is_frozen_on_the_nfce_and_items_add_up_to_the_document_total(): void
    {
        $this->clinic->forceFill(['tax_regime' => 'lucro_presumido'])->save();
        $profile = $this->makeTaxProfile(['name' => 'Presumido 18%', 'icms_csosn' => null, 'icms_cst' => '00', 'icms_rate' => '18', 'pis_cst' => '01', 'pis_rate' => '0.65', 'cofins_cst' => '01', 'cofins_rate' => '3']);
        $racao = $this->makeProduct(['name' => 'Ração', 'price' => 50, 'tax_profile_id' => $profile->id]);
        $coleira = $this->makeProduct(['name' => 'Coleira', 'price' => 30, 'tax_profile_id' => $profile->id]);
        $saleId = $this->paidProductSale([[$racao, 2], [$coleira, 1]], ['discount' => '13.00']);
        // Acréscimo de repasse injetado direto: a conta dele (PLANO 1.1) é coberta em
        // CardSurchargeSettingsTest; aqui só interessa o rateio fiscal do golden (2,60).
        Sale::whereKey($saleId)->update(['surcharge_amount' => '2.60', 'total' => '119.60']);

        $document = $this->issueSale($saleId)->assertCreated()->json('data.0');
        $items = $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/fiscal-documents/{$document['id']}")->assertOk()->json('data.items');

        $this->assertSame('nfce', $document['kind']);
        $this->assertEqualsWithDelta(119.6, $document['total'], 0.001);
        $this->assertSame('2.60', $document['surcharge_amount']);
        $this->assertSame(1, $document['number']);
        $this->assertSame('1', $document['series']);
        $this->assertSame('homologation', $document['environment']);
        $this->assertSame('log', $document['provider']);
        $this->assertMatchesRegularExpression('/^\d{44}$/', $document['access_key']);

        $this->assertSame(
            [['100.00', '10.00', '2.00', '92.00', '92.00', '16.56', '0.49', '2.26'], ['30.00', '3.00', '0.60', '27.60', '27.60', '4.97', '0.15', '0.68']],
            array_map(fn (array $item): array => [$item['gross_total'], $item['discount_share'], $item['surcharge_share'], $item['total'], $item['icms_base'], $item['icms_amount'], $item['pis_amount'], $item['cofins_amount']], $items),
        );
        $this->assertSame(['5102', '5102'], array_column($items, 'cfop'));
        $this->assertSame(['00', '00'], array_column($items, 'cst'));
        $this->assertSame([null, null], array_column($items, 'csosn'));
        $this->assertSame(['product', 'product'], array_column($items, 'profile_source'));
        $this->assertSame(['23091000', '23091000'], array_column($items, 'ncm'));
    }

    public function test_simples_nacional_uses_csosn_from_the_default_profile_without_highlighting_icms(): void
    {
        $saleId = $this->paidProductSale([[$this->makeProduct(['price' => 40]), 1]]);

        $document = FiscalDocument::findOrFail($this->issueSale($saleId)->assertCreated()->json('data.0.id'));
        $item = $document->items()->firstOrFail();

        $this->assertSame('102', $item->csosn);
        $this->assertNull($item->cst);
        $this->assertSame('0.00', (string) $item->icms_amount);
        $this->assertSame('default', $item->profile_source->value);
        $this->assertSame($this->defaultTaxProfile->id, $item->tax_profile_id);
    }

    public function test_numbers_are_sequential_per_model_and_series(): void
    {
        $first = $this->issueSale($this->paidProductSale([[$this->makeProduct(), 1]]))->assertCreated()->json('data.0.number');
        $second = $this->issueSale($this->paidProductSale([[$this->makeProduct(), 1]]))->assertCreated()->json('data.0.number');
        $resale = $this->issueSale($this->paidProductSale([[$this->makeProduct(), 1]], ['operation' => 'in_person_resale']))->assertCreated()->json('data.0');

        $this->assertSame([1, 2], [$first, $second]);
        $this->assertSame(['nfe', 1], [$resale['kind'], $resale['number']]);
    }

    public function test_issuing_lists_every_problem_at_once_and_emits_nothing(): void
    {
        $this->defaultTaxProfile->update(['is_default' => false]);
        $withoutNcm = $this->makeProduct(['name' => 'Petisco sem NCM', 'ncm' => null, 'tax_profile_id' => $this->defaultTaxProfile->id]);
        $withoutProfile = $this->makeProduct(['name' => 'Brinquedo sem perfil']);
        $saleId = $this->paidProductSale([[$withoutNcm, 1], [$withoutProfile, 1]]);

        $errors = $this->issueSale($saleId)->assertStatus(422)->json('errors.fiscal');

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('Petisco sem NCM', $errors[0]);
        $this->assertStringContainsString('Brinquedo sem perfil', $errors[1]);
        $this->assertSame(0, FiscalDocument::count());
    }

    public function test_missing_regime_code_or_cfop_is_refused(): void
    {
        $this->clinic->forceFill(['tax_regime' => null])->save();
        $saleId = $this->paidProductSale([[$this->makeProduct(), 1]]);
        $this->assertStringContainsString('regime tributário', $this->issueSale($saleId)->assertStatus(422)->json('errors.fiscal.0'));

        $this->clinic->forceFill(['tax_regime' => 'lucro_real'])->save();
        $this->assertStringContainsString('sem CST do ICMS', $this->issueSale($saleId)->assertStatus(422)->json('errors.fiscal.0'));

        $this->clinic->forceFill(['tax_regime' => 'simples_nacional'])->save();
        $this->defaultTaxProfile->cfops()->where('operation', 'sale_consumer')->delete();
        $this->assertStringContainsString('sem CFOP para venda a consumidor (dentro do estado)', $this->issueSale($saleId)->assertStatus(422)->json('errors.fiscal.0'));
    }

    public function test_changing_the_profile_later_does_not_rewrite_issued_items(): void
    {
        $document = FiscalDocument::findOrFail($this->issueSale($this->paidProductSale([[$this->makeProduct(), 1]]))->assertCreated()->json('data.0.id'));

        TaxProfile::whereKey($this->defaultTaxProfile->id)->update(['icms_csosn' => '900', 'icms_rate' => '12']);
        $this->defaultTaxProfile->cfops()->where('operation', 'sale_consumer')->update(['cfop' => '5405']);

        $item = $document->items()->firstOrFail();
        $this->assertSame(['102', '5102'], [$item->csosn, $item->cfop]);
    }

    public function test_service_lines_go_to_the_nfse_without_tax_profile(): void
    {
        $this->openRegisterFor($this->receptionist, 0);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $this->makeService(['price' => 60])->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->receptionist, 'cash'), 'amount' => 60])->assertCreated();

        $document = FiscalDocument::findOrFail($this->issueSale($saleId)->assertCreated()->json('data.0.id'));
        $item = $document->items()->firstOrFail();

        $this->assertSame('nfse', $document->kind->value);
        $this->assertNull($document->number);
        $this->assertNull($item->cfop);
        $this->assertNull($item->tax_profile_id);
        $this->assertSame('60.00', (string) $item->total);
    }
}
