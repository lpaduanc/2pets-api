<?php

namespace App\Services\Commercial\Pricing;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Validation\ValidationException;

/**
 * `POST pricing/simulate` (docs/gap-petflow/PLANO.md, 1.4): resolve o contexto da organização de
 * quem pergunta — taxa de cartão de referência, alíquota efetiva e comissão (a informada, senão a
 * do item, senão 0) — e entrega a conta ao `PricingQuoteCalculator`. O front só pré-visualiza; o
 * número vem daqui (seção 3, item 7).
 */
final class PricingSimulator
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly CardFeeReferenceResolver $cardFees,
        private readonly PricingQuoteCalculator $calculator,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validado por `SimulatePricingRequest`
     * @return array<string, mixed>
     */
    public function simulate(User $user, array $input): array
    {
        $organization = $this->scope->primaryOrganization($user);
        $cardFee = $this->cardFees->resolve($user, $organization);
        $tax = $organization?->effective_sales_tax_rate;
        $context = new PricingContext($cardFee->percent, $tax === null ? null : (string) $tax, $this->commissionFor($user, $input));
        $quote = $this->calculator->calculate($input, $context, $cardFee->mode);

        // `params` vazio sai como objeto JSON (`{}`), não lista — o front lê sempre um mapa.
        $quote['alerts'] = array_map(fn (array $alert): array => ['code' => $alert['code'], 'params' => (object) $alert['params']], $quote['alerts']);

        return $quote;
    }

    /** @param  array<string, mixed>  $input */
    private function commissionFor(User $user, array $input): string
    {
        if (isset($input['commission_percent'])) {
            return (string) $input['commission_percent'];
        }

        if (! isset($input['item_type'], $input['item_id'])) {
            return '0';
        }

        return (string) ($this->item($user, $input['item_type'], (int) $input['item_id'])->commission_percent ?? '0');
    }

    private function item(User $user, string $type, int $id): Product|Service
    {
        $query = $type === 'service' ? Service::query() : Product::query();
        $item = $this->scope->scopeQuery($query, $user)->find($id);

        if ($item === null) {
            throw ValidationException::withMessages(['item_id' => __('pricing.item_not_found')]);
        }

        return $item;
    }
}
