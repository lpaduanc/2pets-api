<?php

namespace App\Services\Commercial\Card;

use App\Enums\CardBrand;
use App\Models\PaymentMethod;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Taxa e líquido de um recebimento, num lugar só — usado pelo registro (`SaleService`) e pela
 * prévia do PDV (`simulate-fee`), para a tela nunca mostrar um número e gravar outro.
 *
 * Forma que passa por adquirente (cartão, link recorrente) usa a faixa da bandeira/parcelas e
 * gera a agenda. As demais (Pix, boleto…) mantêm a taxa simples da forma, sem agenda.
 *
 * Faixa com repasse ao cliente: `$amount` é o valor-BASE (o que abate do saldo da venda); taxa,
 * líquido e agenda são calculados sobre o COBRADO (base + acréscimo), que é o que passa na
 * maquininha e o que a adquirente liquida.
 */
final class ReceiptPricing
{
    public function __construct(
        private readonly FeeTierResolver $tiers,
        private readonly ReceivableScheduleService $schedule,
        private readonly FeePassThroughCalculator $passThrough,
    ) {}

    public function quote(PaymentMethod $method, Money $amount, int $installments, ?CardBrand $brand, CarbonImmutable $receivedAt, ?int $organizationId): ReceiptQuote
    {
        $this->assertInstallments($method, $amount, $installments);

        if (! $method->kind->settlesThroughAcquirer()) {
            $fee = Money::of($method->feeFor($amount->toFloat()));

            return new ReceiptQuote($fee, $amount->minus($fee), null, [], Money::zero(), $amount);
        }

        $terms = $this->tiers->resolve($method, $brand, $installments, $receivedAt);
        $surcharge = $this->passThrough->surchargeFor($amount, $terms);
        $charged = $amount->plus($surcharge);
        $plan = $this->schedule->plan($charged, $installments, $terms, $receivedAt, $organizationId);
        $totals = $this->schedule->totals($plan);

        return new ReceiptQuote($totals['fee'], $totals['net'], $terms, $plan, $surcharge, $charged);
    }

    /**
     * Parcelas que a forma aceita, e nunca mais parcelas do que centavos — R$ 0,05 em 24x
     * geraria 19 parcelas de R$ 0,00 "a receber".
     */
    private function assertInstallments(PaymentMethod $method, Money $amount, int $installments): void
    {
        $max = $method->kind->allowsInstallments() ? max(1, (int) $method->max_installments) : 1;

        if ($installments > $max) {
            throw ValidationException::withMessages(['installments' => "Esta forma aceita até {$max} parcela(s)."]);
        }

        if ($amount->cents()->isLessThan($installments)) {
            throw ValidationException::withMessages(['installments' => 'Valor pequeno demais para tantas parcelas.']);
        }
    }
}
