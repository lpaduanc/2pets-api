<?php

namespace Tests\Feature\Commercial;

use App\Models\FinancialAccount;
use App\Models\SaleReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Conciliação de cartões — critérios de aceite do docs/gap-simplesvet/04.
 */
class AcquirerSettlementTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    /**
     * Regressão: `PaymentMethod::feeFor()`/`netFor()` já eram aplicados em `SaleService`
     * antes desta tarefa. Este teste só prova que a rota HTTP nova não quebrou o fluxo.
     */
    public function test_a_card_receipt_records_operator_fee_net_amount_and_expected_settlement_date(): void
    {
        $account = $this->makeAcquirerAccount();
        $cardMethod = $this->makeCardMethod($account->id, feePercent: 2.5, settlementDays: 30);

        $this->openRegisterFor($this->receptionist, 0);
        $product = $this->makeProduct(['price' => 100]);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);

        $today = Carbon::today();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", [
            'payment_method_id' => $cardMethod, 'amount' => 100,
        ])->assertCreated();

        $receipt = SaleReceipt::where('sale_id', $saleId)->sole();
        $this->assertEqualsWithDelta(2.50, (float) $receipt->operator_fee, 0.001);
        $this->assertEqualsWithDelta(97.50, (float) $receipt->net_amount, 0.001);
        $this->assertSame($today->copy()->addDays(30)->toDateString(), $receipt->expected_settlement_date->toDateString());
    }

    public function test_creating_a_deposit_and_reconciling_the_right_receipts_marks_it_reconciled(): void
    {
        $account = $this->makeAcquirerAccount();
        $cardMethod = $this->makeCardMethod($account->id, feePercent: 0, settlementDays: 1);
        $receipt = $this->receiptFor($cardMethod, 100);

        $settlementId = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/acquirer-settlements', [
                'payment_method_id' => $cardMethod,
                'deposit_date' => Carbon::today()->toDateString(),
                'description' => 'Depósito Cielo',
                'destination_account_id' => $account->id,
                'gross_amount' => 100,
                'fee_amount' => 0,
                'net_amount' => 100,
            ])->assertCreated()->json('data.id');

        $this->postJson("/api/professional/acquirer-settlements/{$settlementId}/reconcile", [
            'receipt_ids' => [$receipt->id],
        ])->assertOk()->assertJsonPath('data.status', 'reconciled');
    }

    public function test_a_deposit_that_does_not_match_the_linked_receipts_is_marked_divergent(): void
    {
        $account = $this->makeAcquirerAccount();
        $cardMethod = $this->makeCardMethod($account->id, feePercent: 0, settlementDays: 1);
        $receipt = $this->receiptFor($cardMethod, 100);

        $settlementId = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/acquirer-settlements', [
                'payment_method_id' => $cardMethod,
                'deposit_date' => Carbon::today()->toDateString(),
                'description' => 'Depósito Cielo',
                'gross_amount' => 100,
                'net_amount' => 80,
            ])->assertCreated()->json('data.id');

        $this->postJson("/api/professional/acquirer-settlements/{$settlementId}/reconcile", [
            'receipt_ids' => [$receipt->id],
        ])->assertOk()
            ->assertJsonPath('data.status', 'divergent')
            ->assertJsonPath('data.divergence_note', fn (?string $note): bool => $note !== null);
    }

    public function test_only_the_owner_reconciles_a_deposit(): void
    {
        $account = $this->makeAcquirerAccount();
        $cardMethod = $this->makeCardMethod($account->id, feePercent: 0, settlementDays: 1);
        $receipt = $this->receiptFor($cardMethod, 100);

        $settlementId = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/acquirer-settlements', [
                'payment_method_id' => $cardMethod,
                'deposit_date' => Carbon::today()->toDateString(),
                'description' => 'Depósito Cielo',
                'gross_amount' => 100,
                'net_amount' => 100,
            ])->assertCreated()->json('data.id');

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/acquirer-settlements/{$settlementId}/reconcile", ['receipt_ids' => [$receipt->id]])
            ->assertForbidden();
    }

    private function makeAcquirerAccount(): FinancialAccount
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/financial-accounts', ['name' => 'Cielo', 'type' => 'card_acquirer'])
            ->assertCreated()
            ->json('data.id');

        return FinancialAccount::findOrFail($id);
    }

    private function makeCardMethod(int $accountId, float $feePercent, int $settlementDays): int
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/payment-methods', [
                'name' => 'Maquininha Cielo Crédito',
                'kind' => 'credit_card',
                'acquirer' => 'cielo',
                'direction' => 'in',
                'default_account_id' => $accountId,
                'fee_percent' => $feePercent,
                'settlement_days' => $settlementDays,
            ])->assertCreated()->json('data.id');
    }

    private function receiptFor(int $paymentMethodId, float $amount): SaleReceipt
    {
        $this->openRegisterFor($this->receptionist, 0);
        $product = $this->makeProduct(['price' => $amount]);
        $saleId = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id]);
        $this->postJson("/api/professional/sales/{$saleId}/receipts", [
            'payment_method_id' => $paymentMethodId, 'amount' => $amount,
        ])->assertCreated();

        return SaleReceipt::where('sale_id', $saleId)->sole();
    }
}
