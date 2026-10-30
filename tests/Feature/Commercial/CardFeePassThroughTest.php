<?php

namespace Tests\Feature\Commercial;

use App\Models\FinancialEntry;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodFeeTier;
use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Services\Commercial\Card\FeePassThroughCalculator;
use App\Services\Commercial\Card\FeeTerms;
use App\Services\Commercial\Card\ReceivableScheduleService;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Faixa de taxa que sobrevive à edição e repasse opcional da taxa ao cliente — docs/gap-petflow/
 * PLANO.md, 1.1.
 *
 * Golden do repasse, conta à mão (cobrado = ⌈(base + fixa) / (1 − p)⌉ no centavo):
 *  - R$ 100,00 · 3x · 2,99%: 100 / 0,9701 = 103,0821… → cobrado 103,09, acréscimo 3,09;
 *    taxa 103,09 × 2,99% = 3,082391 → 3,08; líquido 100,01. Bruto 34,37 · 34,36 · 34,36,
 *    taxa rateada 1,03 · 1,03 · 1,02, líquido 33,34 · 33,33 · 33,34.
 *  - R$ 100,00 · 1x · 2,50% + R$ 0,50: 100,50 / 0,975 = 103,0769… → 103,08; taxa 2,577 → 2,58
 *    + 0,50 = 3,08; líquido 100,00.
 */
class CardFeePassThroughTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private PaymentMethod $credit;

    private bool $registerOpen = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        Carbon::setTestNow('2026-10-01 10:00:00');

        $this->credit = PaymentMethod::findOrFail($this->paymentMethodId($this->owner, 'credit_card'));
        $this->credit->update(['max_installments' => 12, 'fee_percent' => '1.5000', 'fee_fixed' => '0.00', 'settlement_days' => 30]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- Tarefa A: faixa estável

    public function test_receipt_keeps_its_fee_tier_after_tiers_are_saved_again_with_ids(): void
    {
        $tiers = $this->saveTiers([$this->tier('visa', 1, 12, '2.99')]);
        $receipt = $this->payCard($this->saleOf(100), 100, 3, 'visa');
        $this->assertSame($tiers[0]['id'], $receipt->fee_tier_id);

        // Mesma faixa, taxa nova, mandada com o id: atualiza no lugar.
        $saved = $this->saveTiers([['id' => $tiers[0]['id']] + $this->tier('visa', 1, 12, '3.49')]);

        $this->assertSame($tiers[0]['id'], $saved[0]['id']);
        $this->assertSame('3.4900', $saved[0]['fee_percent']);
        $this->assertSame($tiers[0]['id'], $receipt->fresh()->fee_tier_id);
        // O que valeu na venda continua congelado.
        $this->assertSame('2.9900', $receipt->fresh()->fee_percent_applied);
    }

    public function test_removed_tier_used_by_a_receipt_is_archived_and_no_longer_resolves(): void
    {
        // A listagem vem ordenada por bandeira: busca o id pela bandeira, não pela posição.
        $tiers = collect($this->saveTiers([$this->tier('visa', 1, 12, '2.99'), $this->tier('mastercard', 1, 12, '3.10')]))->keyBy('card_brand');
        $receipt = $this->payCard($this->saleOf(100), 100, 1, 'visa');

        // Visa fica de fora do PUT; a Mastercard (nunca usada) também.
        $this->saveTiers([$this->tier(null, 1, 12, '4.00')]);

        $visa = PaymentMethodFeeTier::withTrashed()->findOrFail($tiers['visa']['id']);
        $this->assertNotNull($visa->deleted_at);
        $this->assertSame($visa->id, $receipt->fresh()->fee_tier_id);
        // Nunca usada → apagada de fato, sem lixo arquivado.
        $this->assertNull(PaymentMethodFeeTier::withTrashed()->find($tiers['mastercard']['id']));

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/professional/payment-methods/{$this->credit->id}/fee-tiers")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.card_brand', null);

        // Visa agora cai na faixa "qualquer bandeira" (4%), não na arquivada (2,99%).
        $this->assertSame('4.00', $this->simulate('100.00', 1, 'visa')['fee_amount']);
    }

    public function test_tier_id_of_another_payment_method_is_refused(): void
    {
        $debit = PaymentMethod::findOrFail($this->paymentMethodId($this->owner, 'debit_card'));
        $foreign = $debit->feeTiers()->create($this->tier(null, 1, 1, '1.00'));

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/payment-methods/{$this->credit->id}/fee-tiers", ['tiers' => [
                ['id' => $foreign->id] + $this->tier('visa', 1, 12, '2.99'),
            ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tiers.0.id');

        $this->assertSame('1.0000', $foreign->fresh()->fee_percent);
    }

    // ---------------------------------------------------------------- Tarefa B: repasse

    public function test_simulation_with_pass_through_returns_the_golden_values(): void
    {
        $saved = $this->saveTiers([$this->tier('visa', 1, 12, '2.99', passToCustomer: true)]);
        $this->assertTrue($saved[0]['pass_to_customer']);

        $simulated = $this->simulate('100.00', 3, 'visa');

        $this->assertTrue($simulated['pass_to_customer']);
        $this->assertSame('3.09', $simulated['surcharge_amount']);
        $this->assertSame('103.09', $simulated['charged_amount']);
        $this->assertSame('3.08', $simulated['fee_amount']);
        $this->assertSame('100.01', $simulated['net_amount']);
        $this->assertSame(['34.37', '34.36', '34.36'], array_column($simulated['installments'], 'gross_amount'));
        $this->assertSame(['1.03', '1.03', '1.02'], array_column($simulated['installments'], 'fee_amount'));
        $this->assertSame(['33.34', '33.33', '33.34'], array_column($simulated['installments'], 'net_amount'));
    }

    public function test_pass_through_with_fixed_fee_matches_the_golden_values(): void
    {
        $this->saveTiers([$this->tier(null, 1, 12, '2.50', fixed: '0.50', passToCustomer: true)]);

        $simulated = $this->simulate('100.00', 1, null);

        $this->assertSame('3.08', $simulated['surcharge_amount']);
        $this->assertSame('103.08', $simulated['charged_amount']);
        $this->assertSame('3.08', $simulated['fee_amount']);
        $this->assertSame('100.00', $simulated['net_amount']);
    }

    public function test_net_is_never_below_the_base_and_at_most_one_cent_above(): void
    {
        $calculator = new FeePassThroughCalculator;
        $schedule = app(ReceivableScheduleService::class);
        $cases = [['0.01', '2.99', '0'], ['0.99', '4.99', '0.39'], ['100.00', '2.99', '0'], ['220.00', '2.99', '0'],
            ['1234.56', '11.99', '0.39'], ['9999.99', '0.0001', '0'], ['57.33', '99.9999', '0'], ['10.00', '0', '1.00']];

        foreach ($cases as [$base, $percent, $fixed]) {
            $terms = new FeeTerms(1, $percent, $fixed, 30, 30, true);
            $charged = Money::of($base)->plus($calculator->surchargeFor(Money::of($base), $terms));
            $net = $charged->minus($schedule->feeFor($charged, $terms));
            $over = $net->minus($base);

            $this->assertFalse($over->isNegative(), "{$base} a {$percent}% + {$fixed}: líquido {$net} abaixo da base");
            $this->assertTrue($over->isLessThanOrEqualTo('0.01'), "{$base} a {$percent}% + {$fixed}: líquido {$net} passa um centavo");
        }
    }

    public function test_pass_through_of_a_full_fee_is_refused(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/payment-methods/{$this->credit->id}/fee-tiers", ['tiers' => [
                $this->tier(null, 1, 12, '100', passToCustomer: true),
            ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tiers.0.fee_percent');
    }

    public function test_partial_card_payment_with_pass_through_then_cash_closes_the_sale_exactly(): void
    {
        $this->saveTiers([$this->tier('visa', 1, 12, '2.99', passToCustomer: true)]);
        $saleId = $this->saleOf(220);

        $receipt = $this->payCard($saleId, 100, 3, 'visa');

        $this->assertSame('103.09', $receipt->amount);
        $this->assertSame('3.09', $receipt->surcharge_amount);
        $this->assertSame('100.01', $receipt->net_amount);
        $this->assertSame(['34.37', '34.36', '34.36'], $receipt->receivables()->pluck('gross_amount')->all());
        $sale = Sale::findOrFail($saleId);
        $this->assertSame(['220.00', '3.09', '223.09', '103.09'], [$sale->subtotal, $sale->surcharge_amount, $sale->total, $sale->paid_amount]);
        $this->assertSame('120.00', $sale->amountDueMoney()->toString());
        $this->assertSame('unpaid', $sale->status->value);

        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->receptionist, 'cash'), 'amount' => '120.00'])
            ->assertCreated()
            ->assertJsonPath('sale.status', 'paid')
            ->assertJsonPath('sale.surcharge_amount', '3.09')
            ->assertJsonPath('data.surcharge_amount', '0.00');

        $sale->refresh();
        $this->assertSame(['223.09', '223.09', '0.00'], [$sale->total, $sale->paid_amount, $sale->amountDueMoney()->toString()]);

        // Painel do Financeiro: o acréscimo não infla produto/serviço e aparece à parte.
        $received = $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/financial/summary')->assertOk()->json('data.received');
        $this->assertEquals([223.09, 220.0, 3.09], [$received['sales'], $received['sales_services'], $received['sales_surcharge']]);

        $entries = FinancialEntry::where('reference_type', 'sale')->where('reference_id', $saleId)->with('category')->get();
        $this->assertEqualsCanonicalizing(['Serviços' => '220.00', 'Acréscimo de parcelamento' => '3.09'], $entries->mapWithKeys(
            fn (FinancialEntry $entry) => [$entry->category->name => $entry->amount]
        )->all());
    }

    public function test_percentage_discount_does_not_apply_to_the_surcharge(): void
    {
        $this->saveTiers([$this->tier(null, 1, 12, '2.99', passToCustomer: true)]);
        $saleId = $this->saleOf(200);
        $this->postJson("/api/professional/sales/{$saleId}/discount", ['discount_type' => 'percent', 'discount_value' => 10])->assertOk();

        // 180 / 0,9701 = 185,5479… → 185,55; acréscimo 5,55, fora do desconto de 10%.
        $receipt = $this->payCard($saleId, 180, 1, null);

        $sale = Sale::findOrFail($saleId);
        $this->assertSame('5.55', $receipt->surcharge_amount);
        $this->assertSame(['200.00', '20.00', '5.55', '185.55'], [$sale->subtotal, $sale->discount_amount, $sale->surcharge_amount, $sale->total]);
        $this->assertSame('paid', $sale->status->value);
        $this->assertSame(['180.00', '5.55'], FinancialEntry::where('reference_type', 'sale')->where('reference_id', $saleId)
            ->orderByDesc('amount')->pluck('amount')->all());
    }

    public function test_tier_without_pass_through_keeps_the_previous_behavior(): void
    {
        $this->saveTiers([$this->tier('visa', 1, 12, '2.99')]);

        $simulated = $this->simulate('100.00', 3, 'visa');
        $this->assertFalse($simulated['pass_to_customer']);
        $this->assertSame(['0.00', '100.00', '2.99', '97.01'], [$simulated['surcharge_amount'], $simulated['charged_amount'], $simulated['fee_amount'], $simulated['net_amount']]);

        $saleId = $this->saleOf(100);
        $receipt = $this->payCard($saleId, 100, 3, 'visa');
        $this->assertSame(['100.00', '0.00', '97.01'], [$receipt->amount, $receipt->surcharge_amount, $receipt->net_amount]);
        $sale = Sale::findOrFail($saleId);
        $this->assertSame(['0.00', '100.00', 'paid'], [$sale->surcharge_amount, $sale->total, $sale->status->value]);
        $this->assertSame(1, FinancialEntry::where('reference_type', 'sale')->where('reference_id', $saleId)->count());
    }

    // ---------------------------------------------------------------- Apoio

    /** @return array<string, mixed> */
    private function simulate(string $amount, int $installments, ?string $brand): array
    {
        return $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sales/receipts/simulate-fee', array_filter([
                'payment_method_id' => $this->credit->id, 'amount' => $amount, 'installments' => $installments, 'card_brand' => $brand,
            ], fn ($value) => $value !== null))
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  list<array<string, mixed>>  $tiers
     * @return list<array<string, mixed>>
     */
    private function saveTiers(array $tiers): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/payment-methods/{$this->credit->id}/fee-tiers", ['tiers' => $tiers])
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, mixed> */
    private function tier(?string $brand, int $from, int $to, string $percent, string $fixed = '0.00', bool $passToCustomer = false): array
    {
        return [
            'card_brand' => $brand, 'installments_from' => $from, 'installments_to' => $to,
            'fee_percent' => $percent, 'fee_fixed' => $fixed, 'first_settlement_days' => 30, 'interval_days' => 30,
            'pass_to_customer' => $passToCustomer,
        ];
    }

    private function saleOf(int $price): int
    {
        $service = $this->makeService(['price' => $price]);

        if (! $this->registerOpen) {
            $this->openRegisterFor($this->receptionist);
            $this->registerOpen = true;
        }

        $this->actingAs($this->receptionist, 'sanctum');
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'service', 'sellable_id' => $service->id])->assertCreated();

        return $saleId;
    }

    private function payCard(int $saleId, int $amount, int $installments, ?string $brand): SaleReceipt
    {
        $receiptId = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/receipts", array_filter([
                'payment_method_id' => $this->credit->id, 'amount' => $amount, 'installments' => $installments, 'card_brand' => $brand,
            ], fn ($value) => $value !== null))
            ->assertCreated()
            ->json('data.id');

        return SaleReceipt::findOrFail($receiptId);
    }
}
