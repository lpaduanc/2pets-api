<?php

namespace Tests\Feature\Commercial\Reversal;

use App\Enums\ReceivableStatus;
use App\Models\CashRegister;
use App\Models\CashRegisterMovement;
use App\Models\ClientAccountEntry;
use App\Models\CommissionSettlement;
use App\Models\CommissionSettlementItem;
use App\Models\CompanyClientAccount;
use App\Models\FinancialEntry;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItemProfitability;
use App\Models\SaleReceipt;
use App\Models\StockMovement;
use App\Services\Commercial\ClientAccountService;
use App\Services\Finance\SaleRevenueReversalRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Estorno de UM recebimento (docs/gap-petflow/PLANO.md, 2.3): `POST sales/{id}/receipts/{receiptId}/cancel`.
 * Cenário: a recepcionista vende uma ração de R$ 100,00 (estoque 10) no caixa dela; o dono, que
 * tem `sales.cancel_receipt`, estorna.
 */
class SaleReceiptCancellationTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private int $registerId;

    private Product $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        $this->registerId = $this->openRegisterFor($this->receptionist);
        $this->food = $this->makeProduct(['price' => 100, 'average_cost' => '40.0000']);
    }

    public function test_open_origin_register_gets_the_refund_and_the_sale_goes_back_to_open(): void
    {
        [$saleId, $receiptId] = $this->paidSale('cash');
        $this->assertSame(1, SaleItemProfitability::where('sale_id', $saleId)->count());

        $this->cancel($saleId, $receiptId)->assertOk()
            ->assertJsonPath('data.cancel_reason', 'Cliente desistiu na hora')
            ->assertJsonPath('data.cancelled_by.id', $this->owner->id)
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.cancel_blocked_reason', 'already_reversed')
            ->assertJsonPath('sale.status', 'open')
            ->assertJsonPath('sale.amount_due', 100);

        $refund = CashRegisterMovement::where('type', 'refund')->sole();
        $this->assertSame([$this->registerId, '100.00', SaleReceipt::class, $receiptId], [$refund->cash_register_id, (string) $refund->amount, $refund->reference_type, $refund->reference_id]);
        // Receita do PDV estornada (Σ = 0), rentabilidade apagada, estoque NÃO volta.
        $this->assertSame('0.00', number_format((float) FinancialEntry::whereIn('reference_type', SaleRevenueReversalRecorder::REVENUE_REFERENCES)->where('reference_id', $saleId)->sum('net_amount'), 2, '.', ''));
        $this->assertSame(0, SaleItemProfitability::where('sale_id', $saleId)->count());
        $this->assertSame('9.0000', $this->food->fresh()->stock_quantity);

        $log = DB::table('activity_log')->where('event', 'sale.receipt_cancelled')->sole();
        $properties = json_decode($log->properties, true);
        $this->assertSame([$this->owner->id, 'Cliente desistiu na hora', '100.00', 'open'], [(int) $log->causer_id, $properties['reason'], $properties['amount'], $properties['sale_status_after']]);
    }

    public function test_closed_origin_register_refunds_in_the_users_open_register_or_refuses(): void
    {
        [$saleId, $receiptId] = $this->paidSale('cash');
        CashRegister::whereKey($this->registerId)->update(['status' => 'closed', 'closed_at' => now()]);

        $this->cancel($saleId, $receiptId)->assertStatus(422)->assertJsonValidationErrors('cash_register');
        $this->assertNull(SaleReceipt::findOrFail($receiptId)->reversed_at);

        $ownerRegister = $this->openRegisterFor($this->owner);
        $this->cancel($saleId, $receiptId)->assertOk();

        $refund = CashRegisterMovement::where('type', 'refund')->sole();
        $this->assertSame($ownerRegister, $refund->cash_register_id);
        $this->assertStringContainsString("recebido no caixa #{$this->registerId}", $refund->description);
    }

    public function test_pending_receivables_are_cancelled_and_settled_ones_block(): void
    {
        [$saleId, $receiptId] = $this->paidSale('credit_card');
        $receivable = Receivable::where('sale_receipt_id', $receiptId)->sole();

        $receivable->update(['status' => ReceivableStatus::SETTLED->value]);
        $this->cancel($saleId, $receiptId)->assertStatus(422);
        $this->assertSame('paid', Sale::findOrFail($saleId)->status->value);
        $this->assertSame(0, CashRegisterMovement::where('type', 'refund')->count());

        $receivable->update(['status' => ReceivableStatus::PENDING->value]);
        $this->cancel($saleId, $receiptId)->assertOk();
        $this->assertSame(ReceivableStatus::CANCELLED, $receivable->fresh()->status);
    }

    public function test_partial_cancellation_leaves_the_sale_unpaid_with_the_other_receipt(): void
    {
        // O 1º recebimento deixa a venda em aberto: o cliente precisa de fiado liberado (doc 11).
        $this->allowFiado();
        $saleId = $this->saleWithFood();
        $cashId = $this->receive($saleId, 'cash', '60.00');
        $pixId = $this->receive($saleId, 'pix', '40.00');

        $this->cancel($saleId, $pixId)->assertOk()->assertJsonPath('sale.status', 'unpaid')->assertJsonPath('sale.amount_due', 40);
        $this->cancel($saleId, $cashId)->assertOk()->assertJsonPath('sale.status', 'open');
    }

    public function test_fiado_goes_to_zero_when_the_only_receipt_is_cancelled_and_paying_again_does_not_duplicate(): void
    {
        $this->allowFiado();
        $saleId = $this->saleWithFood();
        $receiptId = $this->receive($saleId, 'cash', '60.00');
        $this->complete($saleId)->assertJsonPath('data.status', 'unpaid');
        $this->assertSame('40.00', $this->fiado($saleId));

        $this->cancel($saleId, $receiptId)->assertOk()->assertJsonPath('sale.status', 'open');
        $this->assertSame('0.00', $this->fiado($saleId));
        $adjustment = ClientAccountEntry::where('reference_type', ClientAccountService::RECEIPT_CANCELLATION_REFERENCE)->sole();
        $this->assertSame(['adjustment_credit', '40.00', $receiptId], [$adjustment->type->value, (string) $adjustment->amount, $adjustment->reference_id]);

        // Paga tudo de novo: venda paga, nenhum débito novo.
        $this->receive($saleId, 'cash', '100.00');
        $this->assertSame('0.00', $this->fiado($saleId));
        $this->assertSame(2, ClientAccountEntry::count());
    }

    public function test_fiado_follows_the_amount_due_through_payments_settlement_and_cancellation(): void
    {
        $this->allowFiado();
        $saleId = $this->saleWithFood();
        $first = $this->receive($saleId, 'cash', '30.00');
        $this->complete($saleId);
        $this->assertSame('70.00', $this->fiado($saleId));

        // 2º parcial na venda abate o fiado até o saldo devido.
        $second = $this->receive($saleId, 'pix', '30.00');
        $this->assertSame('40.00', $this->fiado($saleId));
        $adjustment = ClientAccountEntry::where('reference_type', ClientAccountService::RECEIPT_SETTLEMENT_REFERENCE)->sole();
        $this->assertSame(['adjustment_credit', '30.00', $second], [$adjustment->type->value, (string) $adjustment->amount, $adjustment->reference_id]);

        // Estornar um parcial devolve o débito ao saldo devido.
        $this->cancel($saleId, $first)->assertOk()->assertJsonPath('sale.status', 'unpaid')->assertJsonPath('sale.amount_due', 70);
        $this->assertSame('70.00', $this->fiado($saleId));

        // Quitar zera o fiado; estornar o que quitou volta ao saldo devido.
        $last = $this->receive($saleId, 'cash', '70.00');
        $this->assertSame('paid', Sale::findOrFail($saleId)->status->value);
        $this->assertSame('0.00', $this->fiado($saleId));
        $this->cancel($saleId, $last)->assertOk()->assertJsonPath('sale.status', 'unpaid');
        $this->assertSame('70.00', $this->fiado($saleId));
        $this->assertSame(1, ClientAccountEntry::where('type', 'sale_debit')->count());
    }

    public function test_fiado_paid_through_the_account_settles_the_sale_without_double_credit(): void
    {
        $this->allowFiado();
        $saleId = $this->saleWithFood();
        $this->receive($saleId, 'cash', '30.00');
        $this->complete($saleId);

        $accountCredit = PaymentMethod::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'name' => 'Conta corrente', 'kind' => 'account_credit', 'direction' => 'in', 'active' => true,
        ]);
        $payWithAccount = fn () => $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $accountCredit->id, 'amount' => '70.00']);

        // Sem o cliente ter pago nada na conta, quitar "pela conta" é recusado (não há crédito).
        $payWithAccount()->assertStatus(422);

        // O cliente paga os 70 na conta corrente (crédito manual, não aponta para a venda)…
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/clients/{$this->tutor->id}/account-entries", ['type' => 'payment_credit', 'amount' => 70])
            ->assertCreated();
        $this->assertSame('0.00', (string) $this->accountBalance());

        // …e a venda é quitada consumindo esse crédito: consumo e ajuste se anulam no saldo.
        $payWithAccount()->assertCreated();

        $this->assertSame('paid', Sale::findOrFail($saleId)->status->value);
        $this->assertSame('0.00', $this->fiado($saleId));
        $this->assertSame('0.00', (string) $this->accountBalance());
    }

    public function test_sale_back_to_open_and_unpaid_again_is_debited_once(): void
    {
        $this->allowFiado();
        $saleId = $this->saleWithFood();
        $receiptId = $this->receive($saleId, 'cash', '60.00');
        $this->complete($saleId);
        $this->cancel($saleId, $receiptId)->assertOk()->assertJsonPath('sale.status', 'open');

        // De novo em andamento: o parcial não lança fiado; concluir lança, uma vez.
        $this->receive($saleId, 'cash', '50.00');
        $this->assertSame('0.00', $this->fiado($saleId));
        $this->complete($saleId)->assertJsonPath('data.status', 'unpaid');

        $this->assertSame('50.00', $this->fiado($saleId));
        $this->assertSame(2, ClientAccountEntry::where('type', 'sale_debit')->count());
    }

    public function test_closed_commission_is_marked_reversed_without_touching_the_settlement(): void
    {
        $bath = $this->makeService(['price' => 50, 'commission_percent' => 10]);
        $saleId = $this->newSale();
        $this->addItem($saleId, 'service', $bath->id, ['staff_id' => $this->groomerMember->id]);
        $receiptId = $this->receive($saleId, 'cash', '50.00');
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/commission-settlements', [
            'staff_id' => $this->groomerMember->id, 'period_from' => now()->subDay()->toDateString(),
            'period_to' => now()->toDateString(), 'received_until' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.total_amount', 5);

        $this->cancel($saleId, $receiptId)->assertOk();

        $item = CommissionSettlementItem::sole();
        $this->assertNotNull($item->reversed_at);
        $this->assertSame('Cliente desistiu na hora', $item->reversal_reason);
        $this->assertSame('5.00', (string) CommissionSettlement::sole()->total_amount);

        // Paga de novo: a linha volta a ser elegível para um fechamento novo, uma vez só.
        $this->receive($saleId, 'cash', '50.00');
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/professional/commissions/open?staff_id={$this->groomerMember->id}&received_until=".now()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.commission_amount', 5);
    }

    public function test_next_settlement_deducts_the_reversed_commission_when_it_fits(): void
    {
        $this->settledBathCancelled(50);
        $this->openCommissions()->assertJsonPath('pending_deductions.total', '5.00')
            ->assertJsonPath('pending_deductions.items.0.reason', 'Cliente desistiu na hora');

        $this->paidBath(200); // comissão 20,00
        $this->closeSettlement()
            ->assertJsonPath('data.total_amount', 15)
            ->assertJsonPath('data.gross_amount', '20.00')
            ->assertJsonPath('data.deductions_amount', '5.00')
            ->assertJsonPath('data.deductions.0.amount', '5.00')
            ->assertJsonPath('data.deductions.0.reason', 'Cliente desistiu na hora');

        $this->openCommissions()->assertJsonPath('pending_deductions.total', '0.00');
    }

    public function test_deduction_larger_than_the_new_settlement_zeroes_it_and_carries_the_rest(): void
    {
        $this->settledBathCancelled(50); // a recuperar 5,00

        $this->paidBath(30); // comissão 3,00
        $this->closeSettlement()->assertJsonPath('data.total_amount', 0)->assertJsonPath('data.deductions_amount', '3.00');
        $this->openCommissions()->assertJsonPath('pending_deductions.total', '2.00');

        $this->paidBath(100); // comissão 10,00
        $this->closeSettlement()->assertJsonPath('data.total_amount', 8)->assertJsonPath('data.deductions_amount', '2.00');
        $this->openCommissions()->assertJsonPath('pending_deductions.total', '0.00');
        $this->assertSame(['3.00', '2.00'], \App\Models\CommissionSettlementDeduction::orderBy('id')->pluck('amount')->map(fn ($amount): string => (string) $amount)->all());
    }

    public function test_paying_again_after_a_cancellation_does_not_duplicate_stock_revenue_or_snapshot(): void
    {
        [$saleId, $receiptId] = $this->paidSale('cash');
        $this->cancel($saleId, $receiptId)->assertOk();

        $this->receive($saleId, 'cash', '100.00');

        $this->assertSame('9.0000', $this->food->fresh()->stock_quantity);
        $this->assertSame(1, StockMovement::where('reference_type', Sale::class)->where('reference_id', $saleId)->where('type', 'sale_out')->count());
        $this->assertSame('100.00', number_format((float) FinancialEntry::whereIn('reference_type', SaleRevenueReversalRecorder::REVENUE_REFERENCES)->where('reference_id', $saleId)->sum('net_amount'), 2, '.', ''));
        $this->assertSame(1, SaleItemProfitability::where('sale_id', $saleId)->count());
    }

    public function test_sale_with_a_courtesy_line_returns_to_open(): void
    {
        $saleId = $this->newSale();
        $this->addItem($saleId, 'product', $this->food->id);
        $giftId = $this->addItem($saleId, 'product', $this->makeProduct(['name' => 'Brinde', 'price' => 30, 'average_cost' => '10.0000', 'controls_stock' => false])->id);
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/professional/sales/{$saleId}/items/{$giftId}/courtesy", ['kind' => 'gift', 'reason' => 'Brinde'])->assertOk();
        $receiptId = $this->receive($saleId, 'cash', '100.00');

        $this->cancel($saleId, $receiptId)->assertOk()->assertJsonPath('sale.status', 'open')->assertJsonPath('sale.total', 100);
    }

    public function test_permission_reason_and_double_cancellation(): void
    {
        [$saleId, $receiptId] = $this->paidSale('cash');

        // A recepcionista também é vet volante, mas na clínica é atendente: sem a permissão.
        $this->cancel($saleId, $receiptId, $this->receptionist)->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$saleId}/receipts/{$receiptId}/cancel", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->receptionistMember->update(['permissions' => ['sales.cancel_receipt']]);
        $this->cancel($saleId, $receiptId, $this->receptionist)->assertOk();
        $this->cancel($saleId, $receiptId)->assertStatus(422)->assertJsonValidationErrors('receipt');
        $this->assertSame(1, CashRegisterMovement::where('type', 'refund')->count());
    }

    public function test_receipt_resource_tells_who_can_cancel(): void
    {
        [$saleId] = $this->paidSale('cash');

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/sales/{$saleId}/receipts")
            ->assertOk()
            ->assertJsonPath('data.0.can_cancel', true)
            ->assertJsonPath('data.0.cancel_blocked_reason', null)
            ->assertJsonPath('data.0.cancelled_at', null);
        $this->actingAs($this->receptionist, 'sanctum')->getJson("/api/professional/sales/{$saleId}/receipts")
            ->assertOk()
            ->assertJsonPath('data.0.can_cancel', false)
            ->assertJsonPath('data.0.cancel_blocked_reason', 'forbidden');
    }

    /** Banho com comissão de 10%, pago, fechado e estornado. */
    private function settledBathCancelled(int $price): void
    {
        [$saleId, $receiptId] = $this->paidBath($price);
        $this->closeSettlement()->assertCreated();
        $this->cancel($saleId, $receiptId)->assertOk();
    }

    /** @return array{int, int} */
    private function paidBath(int $price): array
    {
        $bath = $this->makeService(['price' => $price, 'commission_percent' => 10]);
        $saleId = $this->newSale();
        $this->addItem($saleId, 'service', $bath->id, ['staff_id' => $this->groomerMember->id]);

        return [$saleId, $this->receive($saleId, 'cash', number_format($price, 2, '.', ''))];
    }

    private function closeSettlement(): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/commission-settlements', [
            'staff_id' => $this->groomerMember->id, 'period_from' => now()->subDay()->toDateString(),
            'period_to' => now()->toDateString(), 'received_until' => now()->toDateString(),
        ]);
    }

    private function openCommissions(): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/professional/commissions/open?staff_id={$this->groomerMember->id}&received_until=".now()->toDateString())
            ->assertOk();
    }

    private function complete(int $saleId): TestResponse
    {
        return $this->actingAs($this->receptionist, 'sanctum')->postJson("/api/professional/sales/{$saleId}/complete")->assertOk();
    }

    private function allowFiado(): void
    {
        CompanyClientAccount::create(['organization_id' => $this->clinic->id, 'client_id' => $this->tutor->id, 'allow_credit_sale' => true, 'credit_limit' => '1000.00']);
    }

    private function accountBalance(): string
    {
        return (string) CompanyClientAccount::where('client_id', $this->tutor->id)->value('current_balance');
    }

    private function fiado(int $saleId): string
    {
        return app(ClientAccountService::class)->saleDebitBalance($saleId)->toString();
    }

    /** @return array{int, int} venda paga e o recebimento */
    private function paidSale(string $kind): array
    {
        $saleId = $this->saleWithFood();

        return [$saleId, $this->receive($saleId, $kind, '100.00')];
    }

    private function saleWithFood(): int
    {
        $saleId = $this->newSale();
        $this->addItem($saleId, 'product', $this->food->id);

        return $saleId;
    }

    private function newSale(): int
    {
        return $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->assertCreated()->json('data.id');
    }

    /** @param  array<string, mixed>  $body */
    private function addItem(int $saleId, string $type, int $id, array $body = []): int
    {
        return $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => $type, 'sellable_id' => $id] + $body)->assertCreated()->json('data.id');
    }

    private function receive(int $saleId, string $kind, string $amount): int
    {
        $method = PaymentMethod::findOrFail($this->paymentMethodId($this->receptionist, $kind));

        return $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $method->id, 'amount' => $amount, 'installments' => 1])
            ->assertCreated()->json('data.id');
    }

    private function cancel(int $saleId, int $receiptId, ?\App\Models\User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/receipts/{$receiptId}/cancel", ['reason' => 'Cliente desistiu na hora']);
    }
}
