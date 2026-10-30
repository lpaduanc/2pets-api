<?php

namespace Tests\Feature\Commercial;

use App\Models\CashRegisterMovement;
use App\Models\ClientAccountEntry;
use App\Models\FinancialEntry;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Services\Commercial\ClientAccountService;
use App\Services\Commercial\Reversal\SaleCancellationReversal;
use App\Services\Finance\SaleReturnFinancialEntryRecorder;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Devolução: saída no financeiro quando o reembolso é em dinheiro (e só nele), e a prévia
 * `POST sales/{sale}/returns/preview` com a mesma conta do salvar, sem gravar nada.
 */
class SaleReturnRefundTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private int $saleId;

    private int $itemId;

    private int $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        $this->actingAs($this->owner, 'sanctum');
        $this->openRegisterFor($this->owner);
        $this->cash = $this->paymentMethodId($this->owner, 'cash');

        // 3 × 10,00 − 1,00 de desconto no cabeçalho = 29,00 pagos: terços com dízima.
        $product = $this->makeProduct(['price' => 10]);
        $this->saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->json('data.id');
        $this->postJson("/api/professional/sales/{$this->saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id, 'quantity' => 3])->assertCreated();
        $this->postJson("/api/professional/sales/{$this->saleId}/discount", ['discount_type' => 'amount', 'discount_value' => 1])->assertOk();
        $this->postJson("/api/professional/sales/{$this->saleId}/receipts", ['payment_method_id' => $this->cash, 'amount' => 29])
            ->assertCreated()->assertJsonPath('sale.status', 'paid');
        $this->itemId = SaleItem::where('sale_id', $this->saleId)->sole()->id;
    }

    public function test_cash_returns_book_expense_entries_that_add_up_to_what_was_paid(): void
    {
        foreach (['9.67', '9.66', '9.67'] as $expected) {
            $this->returnOne('cash')->assertJsonPath('data.total', (float) $expected);
        }

        $entries = FinancialEntry::where('reference_type', 'sale_return')->orderBy('id')->get();
        $this->assertSame(['9.67', '9.66', '9.67'], $entries->pluck('amount')->all());
        $this->assertSame('29.00', Money::sum($entries->pluck('amount'))->toString());
        $this->assertSame(SaleReturn::orderBy('id')->pluck('id')->all(), $entries->pluck('reference_id')->all());
        $this->assertTrue($entries->every(fn (FinancialEntry $entry): bool => $entry->payment_method_id === $this->cash
            && $entry->nature->value === 'expense' && $entry->status->value === 'paid'
            && $entry->accrual_date->isToday()));
    }

    public function test_recording_twice_does_not_duplicate_the_entry(): void
    {
        $this->returnOne('cash');
        $return = SaleReturn::sole();

        app(SaleReturnFinancialEntryRecorder::class)->recordCashRefund($return, null, Money::of('9.67'), Money::zero(), $this->owner);

        $this->assertSame(1, FinancialEntry::where('reference_type', 'sale_return')->count());
    }

    public function test_store_credit_credits_the_client_account_once_and_books_no_cash_out(): void
    {
        $this->returnOne('store_credit');
        $this->returnOne('none');
        $return = SaleReturn::orderBy('id')->firstOrFail();

        $entries = ClientAccountEntry::where('reference_type', 'sale_return')->get();
        $this->assertSame([['return_credit', 'credit', '9.67', $return->id]], $entries->map(fn (ClientAccountEntry $entry): array => [
            $entry->type->value, $entry->direction->value, (string) $entry->amount, $entry->reference_id,
        ])->all());
        $this->assertSame(0, FinancialEntry::where('reference_type', 'sale_return')->count());
        $this->assertSame(0, CashRegisterMovement::where('type', 'refund')->count());

        $ownership = ['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id];
        app(ClientAccountService::class)->creditForReturn($this->tutor, $ownership, 9.67, $return->id, $this->owner);
        $this->assertSame(1, ClientAccountEntry::where('reference_type', 'sale_return')->count());
        $this->assertEqualsWithDelta(9.67, app(ClientAccountService::class)->balanceFor($this->tutor, $ownership), 0.001);
    }

    public function test_cancelling_the_paid_sale_reverses_revenue_and_cash_once(): void
    {
        $this->postJson("/api/professional/sales/{$this->saleId}/cancel", ['reason' => 'Lançada em dobro'])->assertOk();

        $original = FinancialEntry::where('reference_type', 'sale')->where('reference_id', $this->saleId)->get();
        $reversal = FinancialEntry::where('reference_type', 'sale_cancellation')->where('reference_id', $this->saleId)->get();
        $this->assertSame(['29.00'], $original->pluck('amount')->all());
        $this->assertSame(['-29.00'], $reversal->pluck('amount')->all());
        $this->assertSame($original->first()->financial_category_id, $reversal->first()->financial_category_id);
        $this->assertTrue($reversal->first()->accrual_date->isToday());
        $this->assertSame(['29.00'], CashRegisterMovement::where('type', 'refund')->pluck('amount')->all());

        // Rodar de novo não estorna outra vez.
        app(SaleCancellationReversal::class)->reverse(Sale::findOrFail($this->saleId), $this->owner, null);
        $this->assertSame(1, FinancialEntry::where('reference_type', 'sale_cancellation')->count());
        $this->assertSame(1, CashRegisterMovement::where('type', 'refund')->count());
    }

    public function test_preview_uses_the_same_body_and_cumulative_math_without_writing(): void
    {
        $this->returnOne('cash');

        $this->postJson("/api/professional/sales/{$this->saleId}/returns/preview", [
            'sale_id' => $this->saleId, 'reason' => '', 'refund_method' => 'cash',
            'items' => [['sale_item_id' => $this->itemId, 'quantity' => 2]],
        ])->assertOk()->assertExactJson(['data' => [
            'items_refund' => '19.33', 'surcharge_refund' => '0.00', 'total_refund' => '19.33',
            'items' => [['sale_item_id' => $this->itemId, 'quantity' => '2.0000', 'refund_amount' => '19.33']],
        ]]);

        $this->assertSame(1, SaleReturn::count());
        $this->assertSame(1, SaleReturnItem::count());
        $this->assertSame(1, FinancialEntry::where('reference_type', 'sale_return')->count());
    }

    public function test_preview_refuses_what_saving_would_refuse(): void
    {
        $url = "/api/professional/sales/{$this->saleId}/returns/preview";

        $this->postJson($url, ['refund_method' => 'cash', 'items' => [['sale_item_id' => $this->itemId, 'quantity' => 4]]])->assertUnprocessable();
        $this->postJson($url, ['refund_method' => 'cash', 'items' => [['sale_item_id' => 999999, 'quantity' => 1]]])->assertUnprocessable();
        $this->postJson($url, ['sale_id' => $this->saleId + 1, 'refund_method' => 'cash', 'items' => [['sale_item_id' => $this->itemId, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('sale_id');

        $this->actingAs($this->tutor, 'sanctum')
            ->postJson($url, ['refund_method' => 'cash', 'items' => [['sale_item_id' => $this->itemId, 'quantity' => 1]]])
            ->assertNotFound();
    }

    private function returnOne(string $refundMethod): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/professional/sale-returns', [
            'sale_id' => $this->saleId, 'reason' => 'Cliente devolveu', 'refund_method' => $refundMethod,
            'items' => [['sale_item_id' => $this->itemId, 'quantity' => 1]],
        ])->assertCreated();
    }
}
