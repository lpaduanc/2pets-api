<?php

namespace Tests\Feature\Commercial;

use App\Models\Invoice;
use App\Models\Professional;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * "O PDV tem que refletir no financeiro": todo valor lançado no balcão, produto ou serviço,
 * aparece no Financeiro (resumo e lista), no dashboard e no relatório de receita, somado às
 * faturas de atendimento — nunca no lugar delas.
 */
class PdvFinancialReflectionTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_financial_summary_adds_pdv_receipts_split_by_products_and_services_to_invoices(): void
    {
        $this->paidInvoice($this->owner, 200);
        $this->pendingInvoice($this->owner, 80);

        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $pix = $this->paymentMethodId($this->receptionist, 'pix');

        // Venda paga: produto 100 + serviço 60 com 10% de desconto = 144.
        $paid = $this->sale([['product', $this->makeProduct(['price' => 100])->id], ['service', $this->makeService(['price' => 60])->id]]);
        $this->postJson("/api/professional/sales/{$paid}/discount", ['discount_type' => 'percent', 'discount_value' => 10])->assertOk();
        $this->postJson("/api/professional/sales/{$paid}/receipts", ['payment_method_id' => $cash, 'amount' => 100])->assertCreated();
        $this->postJson("/api/professional/sales/{$paid}/receipts", ['payment_method_id' => $pix, 'amount' => 44])->assertCreated();

        // Venda parcial: serviço 50, recebido 20 → 20 recebido, 30 a receber.
        $partial = $this->sale([['service', $this->makeService(['name' => 'Tosa', 'price' => 50])->id]]);
        $this->postJson("/api/professional/sales/{$partial}/receipts", ['payment_method_id' => $cash, 'amount' => 20])->assertCreated();

        // Orçamento nunca é valor a receber.
        $quote = $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'quote'])->json('data.id');
        $this->postJson("/api/professional/sales/{$quote}/items", ['sellable_type' => 'service', 'sellable_id' => $this->makeService(['name' => 'Orçado'])->id]);

        $summary = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/financial/summary')
            ->assertOk()
            ->json('data');

        $this->assertEquals(200, $summary['received']['invoices']);
        $this->assertEquals(164, $summary['received']['sales']);
        $this->assertEquals(364, $summary['received']['total']);
        $this->assertEquals(90, $summary['received']['sales_products']);
        $this->assertEquals(74, $summary['received']['sales_services']);
        $this->assertEquals(80, $summary['pending']['invoices']);
        $this->assertEquals(30, $summary['pending']['sales']);
        $this->assertEquals(110, $summary['pending']['total']);
    }

    public function test_cancelled_sale_leaves_the_received_total(): void
    {
        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $saleId = $this->sale([['product', $this->makeProduct(['price' => 100])->id]]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 100])->assertCreated();

        $this->openRegisterFor($this->owner, 0);
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Devolução'])->assertOk();

        $this->getJson('/api/professional/financial/summary')
            ->assertOk()
            ->assertJsonPath('data.received.sales', 0);
    }

    public function test_financial_entries_list_invoices_and_pdv_sales_together(): void
    {
        $invoice = $this->paidInvoice($this->owner, 200);
        $this->openRegisterFor($this->receptionist, 0);
        $saleId = $this->sale([['product', $this->makeProduct(['price' => 100])->id]]);

        $rows = collect($this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/financial/entries')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->json('data'));

        $sale = $rows->firstWhere('source', 'sale');
        $this->assertSame($saleId, $sale['id']);
        $this->assertSame('pending', $sale['status']);
        $this->assertEquals(100, $sale['amount_due']);
        $this->assertEquals(100, $sale['products_total']);
        $this->assertSame($invoice->id, $rows->firstWhere('source', 'invoice')['id']);

        $this->getJson('/api/professional/financial/entries?source=sale&status=pending')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/professional/financial/entries?status=paid')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.source', 'invoice');
    }

    public function test_financial_view_of_another_clinic_does_not_include_our_sales(): void
    {
        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $saleId = $this->sale([['product', $this->makeProduct(['price' => 100])->id]]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 100])->assertCreated();

        $this->actingAs(User::factory()->professional()->create(), 'sanctum')
            ->getJson('/api/professional/financial/summary')
            ->assertOk()
            ->assertJsonPath('data.received.total', 0);
    }

    public function test_dashboard_monthly_revenue_includes_pdv_receipts(): void
    {
        Professional::factory()->veterinarian()->create(['user_id' => $this->receptionist->id]);
        $this->paidInvoice($this->receptionist, 100);

        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $saleId = $this->sale([['service', $this->makeService(['price' => 60])->id]]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 60])->assertCreated();

        $stats = $this->getJson('/api/professional/dashboard/stats')->assertOk()->json('data.stats');

        $this->assertEquals(160, $stats['monthlyRevenue']);
    }

    public function test_revenue_report_includes_pdv_sales_in_summary_items_months_and_list(): void
    {
        $this->paidInvoice($this->receptionist, 100);
        $this->openRegisterFor($this->receptionist, 0);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $saleId = $this->sale([['product', $this->makeProduct(['name' => 'Coleira', 'price' => 40])->id]]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 40])->assertCreated();

        $report = app(\App\Services\Report\RevenueReportService::class)
            ->generateReport($this->receptionist, now()->startOfMonth(), now()->endOfMonth());

        $this->assertEquals(140, $report['summary']['total_revenue']);
        $this->assertEquals(40, $report['summary']['sales_revenue']);
        $this->assertSame(1, $report['summary']['total_sales']);
        $this->assertEquals(40, collect($report['by_service'])->firstWhere('name', 'Coleira')['revenue']);
        $this->assertEquals(140, collect($report['by_month'])->sum('revenue'));
        $this->assertCount(2, $report['invoices']);
    }

    public function test_editing_an_item_keeps_its_id_and_recalculates_the_sale(): void
    {
        $this->openRegisterFor($this->receptionist, 0);
        $saleId = $this->sale([['product', $this->makeProduct(['price' => 100, 'allow_price_override' => false])->id]]);
        $item = SaleItem::where('sale_id', $saleId)->sole();

        $this->patchJson("/api/professional/sales/{$saleId}/items/{$item->id}", [
            'quantity' => 3, 'staff_id' => $this->groomerMember->id,
        ])->assertOk()
            ->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.staff_id', $this->groomerMember->id)
            ->assertJsonPath('sale.total', 300);

        $this->patchJson("/api/professional/sales/{$saleId}/items/{$item->id}", ['unit_price' => 80])
            ->assertStatus(422);
    }

    /**
     * Venda do PDV pela recepcionista, com os itens informados.
     *
     * @param  list<array{0: string, 1: int}>  $items
     */
    private function sale(array $items): int
    {
        $saleId = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sales', ['kind' => 'sale'])
            ->assertCreated()
            ->json('data.id');

        foreach ($items as [$type, $id]) {
            $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => $type, 'sellable_id' => $id])->assertCreated();
        }

        return $saleId;
    }

    private function paidInvoice(User $author, float $total): Invoice
    {
        return $this->invoice($author, $total, 'paid', now()->toDateString());
    }

    private function pendingInvoice(User $author, float $total): Invoice
    {
        return $this->invoice($author, $total, 'pending', null);
    }

    private function invoice(User $author, float $total, string $status, ?string $paymentDate): Invoice
    {
        return Invoice::create([
            'professional_id' => $author->id,
            'organization_id' => $this->clinic->id,
            'client_id' => $this->tutor->id,
            'invoice_number' => 'INV-'.uniqid(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'payment_date' => $paymentDate,
            'items' => [['description' => 'Consulta', 'quantity' => 1, 'unit_price' => $total, 'total' => $total]],
            'subtotal' => $total,
            'total' => $total,
            'status' => $status,
        ]);
    }
}
