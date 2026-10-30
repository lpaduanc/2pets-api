<?php

namespace App\Services\Commercial\Card;

use App\Enums\CardBrand;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodFeeTier;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Qual taxa vale para forma × bandeira × parcelas × data — docs/gap-petflow/PLANO.md, 1.1.
 *
 * Ordem: faixa da bandeira; senão a faixa "qualquer bandeira"; senão os campos da própria forma
 * (`fee_percent`, `fee_fixed`, `settlement_days`) — compatibilidade com quem ainda não cadastrou
 * faixas. Só faixa vigente na data do recebimento conta.
 */
final class FeeTierResolver
{
    private const DEFAULT_INTERVAL_DAYS = 30;

    public function resolve(PaymentMethod $method, ?CardBrand $brand, int $installments, CarbonInterface $date): FeeTerms
    {
        $candidates = $method->feeTiers->filter(fn (PaymentMethodFeeTier $tier): bool => $this->applies($tier, $installments, $date));
        $tier = $candidates->first(fn (PaymentMethodFeeTier $tier): bool => $brand !== null && $tier->card_brand === $brand)
            ?? $candidates->first(fn (PaymentMethodFeeTier $tier): bool => $tier->card_brand === null);

        if ($tier !== null) {
            return new FeeTerms($tier->id, (string) $tier->fee_percent, (string) $tier->fee_fixed, $tier->first_settlement_days, $tier->interval_days, $tier->pass_to_customer);
        }

        // Forma COM faixas e nenhuma serve: cair na taxa base calaria uma taxa errada (Visa 2,99%
        // cadastrada, bandeira não informada → taxa 0). Melhor recusar e pedir o dado.
        if ($method->feeTiers->isNotEmpty()) {
            throw ValidationException::withMessages([
                $brand === null ? 'card_brand' : 'installments' => $brand === null
                    ? 'Informe a bandeira do cartão: esta forma tem taxa por bandeira.'
                    : sprintf('Nenhuma faixa de taxa cobre %s em %dx. Cadastre a faixa na forma de pagamento.', $brand->label(), $installments),
            ]);
        }

        return new FeeTerms(null, (string) $method->fee_percent, (string) $method->fee_fixed, (int) $method->settlement_days, self::DEFAULT_INTERVAL_DAYS);
    }

    private function applies(PaymentMethodFeeTier $tier, int $installments, CarbonInterface $date): bool
    {
        $day = $date->toDateString();

        return $installments >= $tier->installments_from
            && $installments <= $tier->installments_to
            && ($tier->valid_from === null || $tier->valid_from->toDateString() <= $day)
            && ($tier->valid_to === null || $tier->valid_to->toDateString() >= $day);
    }
}
