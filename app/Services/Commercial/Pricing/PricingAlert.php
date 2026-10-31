<?php

namespace App\Services\Commercial\Pricing;

/**
 * Códigos de alerta da simulação de preço (`POST pricing/simulate`, PLANO 1.4) — contrato com o
 * front, que traduz o código; a ordem dos casos é a ordem em que saem na resposta.
 */
enum PricingAlert: string
{
    case BELOW_MINIMUM = 'below_minimum';
    case BELOW_COST = 'below_cost';
    case NO_CARD_FEE_REFERENCE = 'no_card_fee_reference';
    case NO_TAX_RATE = 'no_tax_rate';
    case MINIMUM_PRICE_UNREACHABLE = 'minimum_price_unreachable';
}
