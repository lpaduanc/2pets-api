<?php

namespace Tests\Unit\Commercial\Pricing;

use App\Enums\CardFeeReferenceMode;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Services\Commercial\Pricing\CardFeeReferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Taxa de cartão de referência do preço mínimo (docs/gap-petflow/PLANO.md, 1.4; decisão 2).
 * Toca o banco (as parcelas são as que o recebimento de verdade gera), por isso estende o
 * `Tests\TestCase` do app.
 *
 * Conta à mão — faixas 1x 2,99% e 2–12x 4,99%, sem tarifa fixa:
 *  - venda A R$ 100,00 em 1x → taxa 2,99; venda B R$ 300,00 em 3x → taxa 14,97;
 *  - média ponderada = (2,99 + 14,97) / 400 = 4,4900% (a média dos percentuais daria 3,99%);
 *  - venda de 2026-06-01 (fora dos 90 dias) entraria e daria (2,99 + 2,99 + 14,97) / 500 = 4,19%.
 *  - Recorte em Brasília: "agora" 2026-10-01 07:00 BRT → início 2026-07-03 00:00 BRT (03:00 UTC).
 */
class CardFeeReferenceResolverTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private const NOW = '2026-10-01 10:00:00';

    private PaymentMethod $credit;

    private bool $registerOpen = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        Carbon::setTestNow(self::NOW);
        $this->credit = PaymentMethod::findOrFail($this->paymentMethodId($this->owner, 'credit_card'));
        $this->credit->update(['max_installments' => 12, 'fee_percent' => '0', 'fee_fixed' => '0.00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_weighted_average_is_the_ratio_of_the_sums_of_the_last_ninety_days(): void
    {
        $this->defineTiers();
        $this->cardSale('100.00', 1, '2026-06-01 12:00:00');
        $this->cardSale('100.00', 1, '2026-09-10 12:00:00');
        $this->cardSale('300.00', 3, '2026-09-20 12:00:00');

        $reference = $this->resolve();

        $this->assertSame(CardFeeReferenceMode::WEIGHTED_90D, $reference->mode);
        $this->assertSame('4.4900', $reference->percent);
    }

    public function test_ninety_day_window_starts_at_midnight_in_sao_paulo(): void
    {
        $this->defineTiers();
        // 2026-07-03 02:30 UTC = 2026-07-02 23:30 BRT: fora. 03:30 UTC = 00:30 BRT: dentro.
        $this->cardSale('100.00', 1, '2026-07-03 02:30:00');
        $this->cardSale('300.00', 3, '2026-07-03 03:30:00');

        $this->assertSame('4.9900', $this->resolve()->percent);
    }

    public function test_cancelled_installments_do_not_count(): void
    {
        $this->defineTiers();
        $this->cardSale('100.00', 1, '2026-09-10 12:00:00');
        $cancelled = $this->cardSale('300.00', 3, '2026-09-20 12:00:00');
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$cancelled}/cancel", ['reason' => 'Teste'])
            ->assertOk();

        $this->assertSame('2.9900', $this->resolve()->percent);
    }

    public function test_weighted_mode_without_history_falls_back_to_the_worst_case(): void
    {
        $this->defineTiers();

        $reference = $this->resolve();

        $this->assertSame(CardFeeReferenceMode::WORST_CASE, $reference->mode);
        $this->assertSame('4.9900', $reference->percent);
    }

    public function test_worst_case_is_the_highest_active_rate_in_scope(): void
    {
        $this->clinic->update(['card_fee_reference_mode' => 'worst_case']);
        $this->defineTiers(extra: [[
            'card_brand' => 'visa', 'installments_from' => 1, 'installments_to' => 12, 'fee_percent' => '9.99', 'fee_fixed' => '0.00',
            'first_settlement_days' => 30, 'interval_days' => 30, 'valid_to' => '2026-09-30',
        ]]);
        // Histórico existe, mas o modo é pior caso: a média não é usada.
        $this->cardSale('100.00', 1, '2026-09-10 12:00:00');
        // Faixa vencida (Visa 9,99% até ontem) não conta. Forma de cartão sem faixa conta pela taxa
        // base; inativa e de outra clínica, não.
        $this->cardMethod(['fee_percent' => '5.50']);
        $this->cardMethod(['fee_percent' => '12.00', 'active' => false]);
        $this->cardMethod(['fee_percent' => '15.00'], Organization::factory()->petshop()->create());

        $reference = $this->resolve();

        $this->assertSame(CardFeeReferenceMode::WORST_CASE, $reference->mode);
        $this->assertSame('5.5000', $reference->percent);
    }

    public function test_without_any_card_rate_the_reference_is_null(): void
    {
        $reference = $this->resolve();

        $this->assertSame(CardFeeReferenceMode::WORST_CASE, $reference->mode);
        $this->assertNull($reference->percent);
    }

    private function resolve(): \App\Services\Commercial\Pricing\CardFeeReference
    {
        return app(CardFeeReferenceResolver::class)->resolve($this->owner, $this->clinic->fresh());
    }

    /** @param  list<array<string, mixed>>  $extra */
    private function defineTiers(array $extra = []): void
    {
        $tier = fn (int $from, int $to, string $fee): array => [
            'card_brand' => null, 'installments_from' => $from, 'installments_to' => $to, 'fee_percent' => $fee,
            'fee_fixed' => '0.00', 'first_settlement_days' => 30, 'interval_days' => 30,
        ];

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/payment-methods/{$this->credit->id}/fee-tiers", ['tiers' => [$tier(1, 1, '2.99'), $tier(2, 12, '4.99'), ...$extra]])
            ->assertOk();
    }

    private function cardSale(string $amount, int $installments, string $at): int
    {
        Carbon::setTestNow($at);
        $this->registerOpen = $this->registerOpen || (bool) $this->openRegisterFor($this->receptionist);
        $saleId = $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $product = $this->makeProduct(['price' => $amount, 'controls_stock' => false]);
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->credit->id, 'amount' => $amount, 'installments' => $installments])
            ->assertCreated();
        Carbon::setTestNow(self::NOW);

        return $saleId;
    }

    /** @param  array<string, mixed>  $attributes */
    private function cardMethod(array $attributes, ?Organization $organization = null): PaymentMethod
    {
        return PaymentMethod::create($attributes + [
            'organization_id' => ($organization ?? $this->clinic)->id,
            'professional_id' => $this->owner->id,
            'name' => 'Maquininha '.uniqid(),
            'kind' => 'credit_card',
            'direction' => 'in',
            'fee_fixed' => '0.00',
            'settlement_days' => 30,
            'max_installments' => 12,
            'active' => true,
        ]);
    }
}
