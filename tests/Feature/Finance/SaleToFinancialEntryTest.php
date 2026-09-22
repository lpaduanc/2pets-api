<?php

namespace Tests\Feature\Finance;

use App\Models\FinancialEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Venda de balcão paga → `financial_entries` de receita, produto e serviço separados —
 * contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md. O rateio de desconto tem
 * que bater com `FinancialOverviewService::saleTotalsByItemType()` (mesma fórmula,
 * reaproveitada — ver `SaleFinancialEntryRecorder`).
 */
class SaleToFinancialEntryTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_a_sale_with_products_and_services_generates_two_revenue_entries_split_by_type(): void
    {
        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');

        $product = $this->makeProduct(['price' => 100]);
        $service = $this->makeService(['price' => 60]);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $service->id]);
        // 10% de desconto sobre 160 = 144, ratear entre produto e serviço.
        $this->postJson("/api/professional/sales/{$saleId}/discount", ['discount_type' => 'percent', 'discount_value' => 10]);

        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 144])
            ->assertCreated()
            ->assertJsonPath('sale.status', 'paid');

        $entries = FinancialEntry::where('reference_type', 'sale')->where('reference_id', $saleId)->get();

        $this->assertSame(2, $entries->count());
        $this->assertEqualsWithDelta(144.0, (float) $entries->sum('net_amount'), 0.01);
        $this->assertTrue($entries->every(fn (FinancialEntry $e) => $e->status->value === 'paid'));
        $this->assertTrue($entries->every(fn (FinancialEntry $e) => $e->nature->value === 'revenue'));

        $productEntry = $entries->firstWhere(fn (FinancialEntry $e) => str_contains($e->description, 'Produtos'));
        $serviceEntry = $entries->firstWhere(fn (FinancialEntry $e) => str_contains($e->description, 'Serviços'));
        $this->assertEqualsWithDelta(90.0, (float) $productEntry->net_amount, 0.01);
        $this->assertEqualsWithDelta(54.0, (float) $serviceEntry->net_amount, 0.01);
    }

    public function test_a_sale_with_only_products_generates_a_single_revenue_entry(): void
    {
        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $product = $this->makeProduct(['price' => 50]);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 50])->assertCreated();

        $entries = FinancialEntry::where('reference_type', 'sale')->where('reference_id', $saleId)->get();
        $this->assertSame(1, $entries->count());
        $this->assertEqualsWithDelta(50.0, (float) $entries->first()->net_amount, 0.01);
    }

    public function test_a_partial_payment_does_not_generate_any_financial_entry_yet(): void
    {
        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $product = $this->makeProduct(['price' => 100]);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 40])
            ->assertCreated()
            ->assertJsonPath('sale.status', 'unpaid');

        $this->assertSame(0, FinancialEntry::where('reference_type', 'sale')->where('reference_id', $saleId)->count());
    }
}
