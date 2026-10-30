<?php

namespace Tests\Feature\Commercial;

use App\Models\AcquirerSettlement;
use App\Models\PaymentMethod;
use App\Models\Receivable;
use App\Models\SaleReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Taxa por bandeira × parcela e agenda de recebíveis — critérios de aceite de
 * docs/gap-petflow/PLANO.md, 1.1 (golden `mdr-01`: R$ 100,00 em 3x a 2,99%).
 */
class CardReceivablesTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private PaymentMethod $credit;

    private bool $registerOpen = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        // Quinta-feira, 01/10/2026: +30 dias cai num sábado (31/10) e rola para segunda (02/11).
        Carbon::setTestNow('2026-10-01 10:00:00');

        $this->credit = PaymentMethod::findOrFail($this->paymentMethodId($this->owner, 'credit_card'));
        $this->credit->update(['max_installments' => 12, 'fee_percent' => '1.5000', 'fee_fixed' => '0.00', 'settlement_days' => 30]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_three_installment_visa_sale_generates_the_golden_receivables(): void
    {
        $this->saveTiers([$this->tier('visa', 1, 12, '2.99')]);

        $receipt = $this->payCard(100, 3, 'visa');

        $this->assertSame('2.99', $receipt->operator_fee);
        $this->assertSame('97.01', $receipt->net_amount);
        $this->assertSame('2.9900', $receipt->fee_percent_applied);
        $rows = $receipt->receivables()->get();
        $this->assertSame(['33.34', '33.33', '33.33'], $rows->pluck('gross_amount')->all());
        $this->assertSame(['1.00', '1.00', '0.99'], $rows->pluck('fee_amount')->all());
        $this->assertSame(['32.34', '32.33', '32.34'], $rows->pluck('net_amount')->all());
        // 31/10 é sábado → 02/11; 30/11 (segunda) e 30/12 (quarta) já são dias úteis.
        $this->assertSame(['2026-11-02', '2026-11-30', '2026-12-30'], $rows->map(fn ($r) => $r->expected_date->toDateString())->all());
    }

    public function test_settlement_date_rolls_to_the_next_business_day(): void
    {
        // 2026-10-01 + 30 = 31/10 (sábado) → segunda 02/11 — se feriado não cadastrado.
        $this->saveTiers([$this->tier(null, 1, 1, '3.00', 30)]);

        $receipt = $this->payCard(50, 1, null);

        $this->assertSame('2026-11-02', $receipt->receivables()->first()->expected_date->toDateString());
    }

    public function test_brand_without_its_own_tier_falls_back_to_any_brand_then_to_the_method(): void
    {
        $this->saveTiers([$this->tier('visa', 1, 12, '2.99'), $this->tier(null, 1, 12, '3.50')]);

        $this->assertSame('3.50', $this->payCard(100, 1, 'elo')->operator_fee);

        $this->saveTiers([]);
        // Sem faixa nenhuma: taxa da própria forma (1,5%).
        $this->assertSame('1.50', $this->payCard(100, 1, 'elo')->operator_fee);
    }

    public function test_changing_the_tier_later_does_not_touch_receivables_already_generated(): void
    {
        $this->saveTiers([$this->tier('visa', 1, 12, '2.99')]);
        $receipt = $this->payCard(100, 3, 'visa');

        $this->saveTiers([$this->tier('visa', 1, 12, '9.99')]);

        $this->assertSame(['1.00', '1.00', '0.99'], $receipt->receivables()->pluck('fee_amount')->all());
    }

    public function test_overlapping_tiers_of_the_same_brand_are_refused(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/payment-methods/{$this->credit->id}/fee-tiers", ['tiers' => [
                $this->tier('visa', 1, 6, '2.99'),
                $this->tier('visa', 6, 12, '3.99'),
            ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tiers.1.installments_from');
    }

    public function test_simulation_matches_what_the_receipt_records(): void
    {
        $this->saveTiers([$this->tier('visa', 1, 12, '2.99')]);

        $simulated = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sales/receipts/simulate-fee', [
                'payment_method_id' => $this->credit->id, 'amount' => '100.00', 'installments' => 3, 'card_brand' => 'visa',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('2.99', $simulated['fee_amount']);
        $this->assertSame('97.01', $simulated['net_amount']);
        $this->assertSame(['32.34', '32.33', '32.34'], array_column($simulated['installments'], 'net_amount'));
    }

    public function test_cancelling_the_sale_cancels_pending_receivables(): void
    {
        $receipt = $this->payCard(100, 2, null);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$receipt->sale_id}/cancel", ['reason' => 'Desistiu'])
            ->assertOk();

        $this->assertSame(['cancelled', 'cancelled'], $receipt->receivables()->get()->map(fn ($r) => $r->status->value)->all());
    }

    public function test_deposit_is_reconciled_per_installment(): void
    {
        $this->saveTiers([$this->tier('visa', 1, 12, '2.99')]);
        $receipt = $this->payCard(100, 3, 'visa');
        $first = $receipt->receivables()->first();

        $expected = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/professional/acquirer-settlements/expected?from=2026-10-01&to=2026-11-15')
            ->assertOk()
            ->json('data.0');
        $this->assertSame([$first->id], $expected['receivable_ids']);

        $settlement = AcquirerSettlement::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'payment_method_id' => $this->credit->id, 'deposit_date' => '2026-10-31',
            'description' => 'Depósito parcela 1', 'gross_amount' => '33.34', 'fee_amount' => '1.00', 'net_amount' => '32.34',
        ]);

        $this->postJson("/api/professional/acquirer-settlements/{$settlement->id}/reconcile", ['receivable_ids' => [$first->id]])
            ->assertOk()
            ->assertJsonPath('data.status', 'reconciled');

        $this->assertSame('settled', $first->fresh()->status->value);
        $this->assertSame(2, Receivable::where('sale_receipt_id', $receipt->id)->where('status', 'pending')->count());
    }

    public function test_an_installment_settled_by_one_deposit_cannot_be_settled_by_another(): void
    {
        $this->saveTiers([$this->tier('visa', 1, 12, '2.99')]);
        $first = $this->payCard(100, 3, 'visa')->receivables()->first();
        $this->actingAs($this->owner, 'sanctum');
        $a = $this->deposit('32.34');
        $b = $this->deposit('32.34');

        $this->postJson("/api/professional/acquirer-settlements/{$a->id}/reconcile", ['receivable_ids' => [$first->id]])->assertOk();

        // Revisão de centavo (achado 1): a mesma parcela entraria em dois depósitos.
        $this->postJson("/api/professional/acquirer-settlements/{$b->id}/reconcile", ['receivable_ids' => [$first->id]])
            ->assertStatus(422);
        $this->assertSame($a->id, $first->fresh()->acquirer_settlement_id);
    }

    public function test_method_with_brand_tiers_refuses_a_receipt_without_brand_instead_of_charging_zero(): void
    {
        // Achado 4: Visa a 2,99% cadastrada e taxa base da forma 0 — sem bandeira, a taxa seria 0.
        $this->credit->update(['fee_percent' => '0']);
        $this->saveTiers([$this->tier('visa', 1, 12, '2.99')]);

        $this->postJson('/api/professional/sales/receipts/simulate-fee', ['payment_method_id' => $this->credit->id, 'amount' => '100.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('card_brand');
    }

    public function test_installments_beyond_the_method_or_the_cents_are_refused(): void
    {
        $simulate = fn (string $amount, int $installments) => $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/sales/receipts/simulate-fee', ['payment_method_id' => $this->credit->id, 'amount' => $amount, 'installments' => $installments]);

        $simulate('100.00', 13)->assertStatus(422)->assertJsonValidationErrors('installments');
        // Achado 7: R$ 0,05 em 12x geraria parcelas de R$ 0,00.
        $simulate('0.05', 12)->assertStatus(422)->assertJsonValidationErrors('installments');
    }

    public function test_receivables_of_another_clinic_are_invisible(): void
    {
        $this->payCard(100, 2, null);
        $outsider = \App\Models\User::factory()->professional()->create();

        $this->actingAs($outsider, 'sanctum')->getJson('/api/professional/receivables')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($outsider, 'sanctum')->getJson("/api/professional/payment-methods/{$this->credit->id}/fee-tiers")->assertNotFound();
    }

    private function deposit(string $net): AcquirerSettlement
    {
        return AcquirerSettlement::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'payment_method_id' => $this->credit->id, 'deposit_date' => '2026-11-02',
            'description' => 'Depósito', 'gross_amount' => $net, 'fee_amount' => '0.00', 'net_amount' => $net,
        ]);
    }

    /** @param  list<array<string, mixed>>  $tiers */
    private function saveTiers(array $tiers): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/payment-methods/{$this->credit->id}/fee-tiers", ['tiers' => $tiers])
            ->assertOk();
    }

    /** @return array<string, mixed> */
    private function tier(?string $brand, int $from, int $to, string $percent, int $firstDays = 30): array
    {
        return [
            'card_brand' => $brand, 'installments_from' => $from, 'installments_to' => $to,
            'fee_percent' => $percent, 'fee_fixed' => '0.00', 'first_settlement_days' => $firstDays, 'interval_days' => 30,
        ];
    }

    private function payCard(int $amount, int $installments, ?string $brand): SaleReceipt
    {
        $service = $this->makeService(['price' => $amount]);

        if (! $this->registerOpen) {
            $this->openRegisterFor($this->receptionist);
            $this->registerOpen = true;
        }

        $this->actingAs($this->receptionist, 'sanctum');
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $service->id])->assertCreated();

        $receiptId = $this->postJson("/api/professional/sales/{$saleId}/receipts", array_filter([
            'payment_method_id' => $this->credit->id, 'amount' => $amount, 'installments' => $installments, 'card_brand' => $brand,
        ], fn ($value) => $value !== null))->assertCreated()->json('data.id');

        return SaleReceipt::findOrFail($receiptId);
    }
}
