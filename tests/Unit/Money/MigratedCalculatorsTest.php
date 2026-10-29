<?php

namespace Tests\Unit\Money;

use App\Enums\DiscountType;
use App\Enums\FinancialEntryStatus;
use App\Models\FinancialEntry;
use App\Models\PaymentMethod;
use App\Models\SaleItem;
use App\Services\Finance\FinancialEntrySettlementCalculator;
use App\Services\Finance\FinancialInstallmentPlanner;
use App\Services\Purchase\InstallmentPlanner;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Fórmulas migradas para `Money` na fase 0.1 (docs/gap-petflow/PLANO.md): fixa os números que
 * já valiam antes e os dois pontos em que o float errava — a tolerância de um centavo da baixa
 * e o meio centavo. Sem banco: os models são montados em memória.
 */
class MigratedCalculatorsTest extends TestCase
{
    public function test_card_fee_adds_percent_and_fixed_before_rounding_once(): void
    {
        $method = (new PaymentMethod)->forceFill(['fee_percent' => '2.9900', 'fee_fixed' => '0.39']);

        // 247,00 × 2,99% = 7,3853 + 0,39 = 7,7753 → 7,78, arredondado uma vez só.
        $this->assertSame(7.78, $method->feeFor(247.00));
        $this->assertSame(239.22, $method->netFor(247.00));

        $tiny = (new PaymentMethod)->forceFill(['fee_percent' => '0.9000', 'fee_fixed' => '0.00']);
        $this->assertSame(0.01, $tiny->feeFor(1.00)); // 0,009 → 0,01
    }

    public function test_sale_item_total_margin_and_net_unit_price(): void
    {
        $item = (new SaleItem)->forceFill([
            'quantity' => '1.250', 'unit_price' => '32.90', 'unit_cost' => '20.0000', 'discount' => '0.00',
        ]);

        // Golden `balanca-01`: 1,250 kg × 32,90 = 41,125 → 41,13.
        $this->assertSame(41.13, $item->calculateTotal());
        $this->assertSame(16.13, $item->marginAmount()); // 41,13 − 25,00
        $this->assertSame('32.90', $item->netUnitPrice()); // 41,13 / 1,250 = 32,904 → 32,90 (exibição; a devolução usa o rateio, não este × quantidade)

        $discounted = (new SaleItem)->forceFill(['quantity' => '3', 'unit_price' => '10.00', 'discount' => '50.00']);
        $this->assertSame(0.0, $discounted->calculateTotal(), 'Desconto maior que a linha não deixa total negativo.');
    }

    public function test_discount_is_capped_by_subtotal_and_by_one_hundred_percent(): void
    {
        $this->assertSame(100.0, DiscountType::AMOUNT->amountFor(100.0, 150.0));
        $this->assertSame(100.0, DiscountType::PERCENT->amountFor(100.0, 120.0));
        $this->assertSame(23.47, DiscountType::PERCENT->amountFor(234.65, 10.0)); // 23,465 → 23,47
        $this->assertSame(0.0, DiscountType::NONE->amountFor(100.0, 10.0));
    }

    public function test_installments_put_the_leftover_cent_on_the_last_one(): void
    {
        $first = CarbonImmutable::parse('2026-11-03');

        $purchase = array_column((new InstallmentPlanner)->plan(100.00, 3, $first), 'amount');
        $entry = (new FinancialInstallmentPlanner)->plan(100.00, 3, $first)->map->amount->all();

        $this->assertSame([33.33, 33.33, 33.34], $purchase);
        $this->assertSame([33.33, 33.33, 33.34], $entry);
    }

    public function test_settlement_accepts_exactly_one_cent_over_the_net_amount(): void
    {
        // Em float, 100,01 − 100,00 = 0,0100000000000051 > 0,01 e a baixa era recusada.
        $result = $this->settle(amount: '100.00', paid: 100.01);

        $this->assertSame(FinancialEntryStatus::PAID, $result['status']);
        $this->assertSame(100.0, $result['net_amount']);
    }

    public function test_settlement_within_one_cent_below_the_net_amount_is_paid(): void
    {
        // Em float, 0,06 >= 0,07 − 0,01 dava falso e a linha ficava "parcialmente paga" por um centavo.
        $this->assertSame(FinancialEntryStatus::PAID, $this->settle(amount: '0.07', paid: 0.06)['status']);
        $this->assertSame(FinancialEntryStatus::PARTIALLY_PAID, $this->settle(amount: '0.07', paid: 0.05)['status']);
    }

    public function test_settlement_refuses_more_than_one_cent_over_the_net_amount(): void
    {
        $this->expectException(HttpException::class);

        $this->settle(amount: '100.00', paid: 100.02);
    }

    public function test_settlement_applies_discount_fine_and_interest_on_the_same_line(): void
    {
        $result = $this->settle(amount: '200.00', paid: 207.50, extra: ['discount' => 2.5, 'fine' => 4, 'interest' => '6.00']);

        $this->assertSame(207.5, $result['net_amount']);
        $this->assertSame(FinancialEntryStatus::PAID, $result['status']);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function settle(string $amount, float $paid, array $extra = []): array
    {
        $entry = (new FinancialEntry)->forceFill(['amount' => $amount, 'discount' => '0.00', 'fine' => '0.00', 'interest' => '0.00']);

        return (new FinancialEntrySettlementCalculator)->calculate($entry, ['paid_amount' => $paid, ...$extra], null);
    }
}
