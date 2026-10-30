<?php

namespace Tests\Feature\Commercial;

use App\Enums\FinancialNature;
use App\Models\CashRegisterMovement;
use App\Models\FinancialEntry;
use App\Models\FiscalDocument;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Configurações do acréscimo de repasse da taxa (docs/gap-petflow/PLANO.md, 1.1) que o contador
 * de cada clínica decide: acréscimo no documento fiscal e acréscimo na devolução.
 *
 * Golden, conta à mão (taxa 2,99% em 1x, cobrado = ⌈base / 0,9701⌉ no centavo):
 *  - Fiscal: produto 200 + serviço 50 = 250 → 257,7054… → 257,71, acréscimo 7,71. Rateio
 *    200:50 em centavos: 771×200/250 = 616,8 e 771×50/250 = 154,2 → pisos 616 + 154 = 770,
 *    o centavo que sobra vai ao maior resto (produto) → 6,17 · 1,54. Documentos 206,17 · 51,54.
 *  - Devolução: produto 3 × 10 = 30 + serviço 70 = 100 → 103,0821… → 103,09, acréscimo 3,09.
 *    Rateio 30:70: 92,7 e 216,3 → 92 + 216, sobra ao produto → 0,93 · 2,16. Devolver 1 de 3
 *    leva 0,93 × 1/3 = 0,31; o resto (2 un. + serviço) leva 0,62 + 2,16 = 2,78. Σ = 3,09.
 *  - Quantidade decimal: 1,5 kg × 10 = 15 → 15,4623… → 15,47, acréscimo 0,47. Devolver 0,7 kg
 *    leva 0,47 × 0,7/1,5 = 0,2193… → 0,22; os 0,8 kg restantes levam 0,47 − 0,22 = 0,25.
 */
class CardSurchargeSettingsTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private PaymentMethod $credit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        Carbon::setTestNow('2026-10-01 10:00:00');

        $this->credit = PaymentMethod::findOrFail($this->paymentMethodId($this->owner, 'credit_card'));
        $this->credit->update(['max_installments' => 12, 'fee_percent' => '1.5000', 'fee_fixed' => '0.00', 'settlement_days' => 30]);
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/payment-methods/{$this->credit->id}/fee-tiers", ['tiers' => [[
                'card_brand' => null, 'installments_from' => 1, 'installments_to' => 12, 'fee_percent' => '2.99',
                'fee_fixed' => '0.00', 'first_settlement_days' => 30, 'interval_days' => 30, 'pass_to_customer' => true,
            ]]])
            ->assertOk();

        $this->openRegisterFor($this->receptionist);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- fiscal-settings

    public function test_fiscal_settings_expose_the_surcharge_defaults(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/fiscal-settings')
            ->assertOk()
            ->assertJsonPath('data.card_surcharge_on_invoice', true)
            ->assertJsonPath('data.card_surcharge_return_policy', 'keep');
    }

    public function test_fiscal_settings_update_the_surcharge_fields_independently(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/professional/fiscal-settings', ['card_surcharge_return_policy' => 'proportional'])
            ->assertOk()
            ->assertJsonPath('data.card_surcharge_on_invoice', true)
            ->assertJsonPath('data.card_surcharge_return_policy', 'proportional');

        $this->putJson('/api/professional/fiscal-settings', ['card_surcharge_on_invoice' => false])
            ->assertOk()
            ->assertJsonPath('data.card_surcharge_on_invoice', false)
            ->assertJsonPath('data.card_surcharge_return_policy', 'proportional');

        // Campo ausente não zera o que estava gravado.
        $this->putJson('/api/professional/fiscal-settings', ['cnae_code' => '4789004'])
            ->assertOk()
            ->assertJsonPath('data.card_surcharge_on_invoice', false)
            ->assertJsonPath('data.card_surcharge_return_policy', 'proportional');
    }

    public function test_fiscal_settings_reject_invalid_surcharge_values(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/professional/fiscal-settings', [
                'card_surcharge_on_invoice' => 'talvez',
                'card_surcharge_return_policy' => 'half',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['card_surcharge_on_invoice', 'card_surcharge_return_policy']);

        $this->putJson('/api/professional/fiscal-settings', ['card_surcharge_on_invoice' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('card_surcharge_on_invoice');

        $this->assertTrue($this->clinic->fresh()->card_surcharge_on_invoice);
    }

    // ---------------------------------------------------------------- documento fiscal

    public function test_surcharge_on_invoice_is_allocated_between_documents_and_totals_match_the_sale(): void
    {
        $saleId = $this->paidMixedSale();

        $documents = collect($this->issue($saleId))->keyBy('kind');

        $this->assertSame('7.71', Sale::findOrFail($saleId)->surcharge_amount);
        $this->assertSame(['6.17', '1.54'], [$documents['nfce']['surcharge_amount'], $documents['nfse']['surcharge_amount']]);
        $this->assertSame(['206.17', '51.54'], FiscalDocument::orderBy('kind')->pluck('total')->all());
        $this->assertSame(
            Sale::findOrFail($saleId)->total,
            Money::sum(FiscalDocument::where('sale_id', $saleId)->pluck('total'))->toString(),
        );
    }

    public function test_surcharge_off_invoice_keeps_documents_without_it(): void
    {
        $this->clinic->update(['card_surcharge_on_invoice' => false]);
        $saleId = $this->paidMixedSale();

        $documents = collect($this->issue($saleId))->keyBy('kind');

        $this->assertSame(['0.00', '0.00'], [$documents['nfce']['surcharge_amount'], $documents['nfse']['surcharge_amount']]);
        $this->assertSame(['200.00', '50.00'], FiscalDocument::orderBy('kind')->pluck('total')->all());
    }

    // ---------------------------------------------------------------- devolução

    public function test_keep_policy_never_refunds_the_surcharge(): void
    {
        [$saleId, $product, $service] = $this->paidSaleForReturns();

        $response = $this->returnItems($saleId, [[$product, 3], [$service, 1]]);

        $response->assertJsonPath('data.surcharge_refund', '0.00')->assertJsonPath('data.total', 100);
    }

    public function test_proportional_policy_refunds_the_whole_surcharge_across_partial_returns(): void
    {
        $this->clinic->update(['card_surcharge_return_policy' => 'proportional']);
        [$saleId, $product, $service] = $this->paidSaleForReturns();
        $this->assertSame('3.09', Sale::findOrFail($saleId)->surcharge_amount);

        $this->returnItems($saleId, [[$product, 1]])
            ->assertJsonPath('data.surcharge_refund', '0.31')
            ->assertJsonPath('data.total', 10.31);
        $this->assertTrue(CashRegisterMovement::where('type', 'refund')->where('amount', '10.31')->exists());

        $this->returnItems($saleId, [[$product, 2], [$service, 1]])
            ->assertJsonPath('data.surcharge_refund', '2.78')
            ->assertJsonPath('data.total', 92.78);

        // Tudo devolvido: exatamente o acréscimo cobrado e o total pago, nem um centavo a mais.
        $sale = Sale::findOrFail($saleId);
        $this->assertSame('3.09', Money::sum(SaleReturn::where('sale_id', $saleId)->pluck('surcharge_refund'))->toString());
        $this->assertSame($sale->total, Money::sum(SaleReturn::where('sale_id', $saleId)->pluck('total'))->toString());
    }

    public function test_cash_return_books_items_and_surcharge_refund_in_the_financial_and_preview_matches(): void
    {
        $this->clinic->update(['card_surcharge_return_policy' => 'proportional']);
        [$saleId, $product, $service] = $this->paidSaleForReturns();
        $body = ['refund_method' => 'cash', 'items' => [['sale_item_id' => $product, 'quantity' => 1]]];

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/returns/preview", $body)
            ->assertOk()
            ->assertExactJson(['data' => [
                'items_refund' => '10.00', 'surcharge_refund' => '0.31', 'total_refund' => '10.31',
                'items' => [['sale_item_id' => $product, 'quantity' => '1.0000', 'refund_amount' => '10.00']],
            ]]);
        $this->assertSame(0, SaleReturn::count());

        $first = $this->returnItems($saleId, [[$product, 1]])->json('data.id');
        $this->returnItems($saleId, [[$product, 2], [$service, 1]]);

        $entries = FinancialEntry::where('reference_type', 'sale_return')->with('category')->orderBy('id')->get();
        $this->assertSame(
            [[$first, 'Devoluções de vendas', '10.00'], [$first, 'Estorno de acréscimo de parcelamento', '0.31']],
            $entries->take(2)->map(fn (FinancialEntry $entry): array => [$entry->reference_id, $entry->category->name, $entry->amount])->all(),
        );
        $this->assertTrue($entries->every(fn (FinancialEntry $entry): bool => $entry->nature === FinancialNature::EXPENSE
            && $entry->accrual_date->toDateString() === '2026-10-01' && $entry->payment_method_id === $this->credit->id));
        // Σ saídas = Σ devoluções = total pago (itens + acréscimo), ao centavo.
        $sale = Sale::findOrFail($saleId);
        $this->assertSame($sale->total, Money::sum($entries->pluck('amount'))->toString());
        $this->assertSame('3.09', Money::sum($entries->filter(fn (FinancialEntry $entry): bool => $entry->category->name === 'Estorno de acréscimo de parcelamento')->pluck('amount'))->toString());
    }

    public function test_cancelling_a_card_sale_reverses_revenue_with_surcharge_and_pending_receivables(): void
    {
        [$saleId] = $this->paidSaleForReturns();
        $original = FinancialEntry::where('reference_type', 'sale')->where('reference_id', $saleId)->with('category')->get();
        $this->assertContains('Acréscimo de parcelamento', $original->pluck('category.name')->all());

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Cobrado errado'])->assertOk();

        $reversals = FinancialEntry::where('reference_type', 'sale_cancellation')->where('reference_id', $saleId)->get();
        $this->assertCount($original->count(), $reversals);
        foreach ($original as $entry) {
            $this->assertSame('0.00', Money::sum($reversals->where('financial_category_id', $entry->financial_category_id)->pluck('amount')->push($entry->amount))->toString());
        }
        $this->assertSame(0, \App\Models\Receivable::whereIn('sale_receipt_id', \App\Models\SaleReceipt::where('sale_id', $saleId)->select('id'))->pending()->count());
        $this->assertNotNull(\App\Models\SaleReceipt::where('sale_id', $saleId)->sole()->reversed_at);
    }

    public function test_cancel_is_refused_when_the_acquirer_already_settled_a_receivable(): void
    {
        [$saleId] = $this->paidSaleForReturns();
        \App\Models\Receivable::whereIn('sale_receipt_id', \App\Models\SaleReceipt::where('sale_id', $saleId)->select('id'))->update(['status' => 'settled']);

        $message = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Cobrado errado'])
            ->assertUnprocessable()
            ->json('message');
        $this->assertStringContainsString('já foi pago ou antecipado pela adquirente', $message);

        $this->assertSame('paid', Sale::findOrFail($saleId)->status->value);
        $this->assertSame(0, FinancialEntry::where('reference_type', 'sale_cancellation')->count());
        $this->assertNull(\App\Models\SaleReceipt::where('sale_id', $saleId)->sole()->reversed_at);
    }

    public function test_proportional_policy_with_decimal_quantity(): void
    {
        $this->clinic->update(['card_surcharge_return_policy' => 'proportional']);
        $product = $this->makeProduct(['price' => 10, 'unit_of_sale' => 'KG', 'decimal_places_allowed' => 3, 'controls_stock' => false]);
        $saleId = $this->newSale();
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id, 'quantity' => '1.5'])->assertCreated();
        $this->payCard($saleId, '15.00');
        $itemId = SaleItem::where('sale_id', $saleId)->sole()->id;
        $this->assertSame('0.47', Sale::findOrFail($saleId)->surcharge_amount);

        $this->returnItems($saleId, [[$itemId, '0.7']])
            ->assertJsonPath('data.surcharge_refund', '0.22')
            ->assertJsonPath('data.total', 7.22);
        $this->returnItems($saleId, [[$itemId, '0.8']])
            ->assertJsonPath('data.surcharge_refund', '0.25')
            ->assertJsonPath('data.total', 8.25);
    }

    // ---------------------------------------------------------------- Apoio

    private function paidMixedSale(): int
    {
        $saleId = $this->newSale();
        $product = $this->makeProduct(['price' => 200, 'controls_stock' => false]);
        $service = $this->makeService(['price' => 50]);
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $service->id])->assertCreated();
        $this->payCard($saleId, '250.00');

        return $saleId;
    }

    /** @return array{int, int, int} venda, item de produto (3 un.), item de serviço */
    private function paidSaleForReturns(): array
    {
        $saleId = $this->newSale();
        $product = $this->makeProduct(['price' => 10, 'controls_stock' => false]);
        $service = $this->makeService(['price' => 70]);
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id, 'quantity' => 3])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $service->id])->assertCreated();
        $this->payCard($saleId, '100.00');

        $items = SaleItem::where('sale_id', $saleId)->get();

        return [
            $saleId,
            $items->firstWhere('sellable_type', \App\Models\Product::class)->id,
            $items->firstWhere('sellable_type', '!=', \App\Models\Product::class)->id,
        ];
    }

    private function newSale(): int
    {
        return $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sales', ['kind' => 'sale'])
            ->assertCreated()
            ->json('data.id');
    }

    private function payCard(int $saleId, string $amount): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->credit->id, 'amount' => $amount, 'installments' => 1])
            ->assertCreated()
            ->assertJsonPath('sale.status', 'paid');
    }

    /** @return list<array<string, mixed>> */
    private function issue(int $saleId): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/fiscal-documents")
            ->assertCreated()
            ->json('data');
    }

    /** @param  list<array{int, int|string}>  $lines */
    private function returnItems(int $saleId, array $lines, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->receptionist, 'sanctum')
            ->postJson('/api/professional/sale-returns', [
                'sale_id' => $saleId, 'reason' => 'Cliente devolveu', 'refund_method' => 'cash',
                'items' => array_map(fn (array $line): array => ['sale_item_id' => $line[0], 'quantity' => $line[1]], $lines),
            ])
            ->assertCreated();
    }
}
