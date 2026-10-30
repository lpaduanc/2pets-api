<?php

namespace App\Services\Commercial\Card;

use App\Enums\CardSurchargeReturnPolicy;
use App\Models\Organization;
use App\Models\Sale;

/**
 * Como a organização trata o acréscimo de repasse da taxa do cartão (docs/gap-petflow/PLANO.md,
 * 1.1): se entra no documento fiscal e se volta na devolução. Ponto ÚNICO de leitura, usado pela
 * emissão fiscal e pela devolução — cada uma lê no momento em que acontece, então mudar a
 * configuração vale para o próximo documento/devolução, nunca reescreve o passado.
 *
 * Profissional sem organização (autônomo) não tem onde guardar a escolha: recebe os padrões, em
 * vez de quebrar.
 */
final readonly class CardSurchargePolicy
{
    public const DEFAULT_ON_INVOICE = true;

    public const DEFAULT_RETURN_POLICY = CardSurchargeReturnPolicy::KEEP;

    public function __construct(
        public bool $onInvoice = self::DEFAULT_ON_INVOICE,
        public CardSurchargeReturnPolicy $returnPolicy = self::DEFAULT_RETURN_POLICY,
    ) {}

    public static function forSale(Sale $sale): self
    {
        return self::forOrganization($sale->organization_id === null ? null : Organization::find($sale->organization_id));
    }

    public static function forOrganization(?Organization $organization): self
    {
        if ($organization === null) {
            return new self;
        }

        return new self(
            (bool) ($organization->card_surcharge_on_invoice ?? self::DEFAULT_ON_INVOICE),
            $organization->card_surcharge_return_policy ?? self::DEFAULT_RETURN_POLICY,
        );
    }

    public function refundsProportionally(): bool
    {
        return $this->returnPolicy === CardSurchargeReturnPolicy::PROPORTIONAL;
    }
}
