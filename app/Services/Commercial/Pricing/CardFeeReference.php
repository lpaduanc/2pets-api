<?php

namespace App\Services\Commercial\Pricing;

use App\Enums\CardFeeReferenceMode;

/**
 * Taxa de cartão que entra no preço mínimo e o modo que a produziu (PLANO 1.4). `mode` é o modo
 * EFETIVO: a média de 90 dias sem histórico cai para o pior caso, e a resposta diz isso.
 * `percent` `null` = nenhuma taxa cadastrada (o preço mínimo usa 0 e avisa).
 */
final readonly class CardFeeReference
{
    public function __construct(
        public CardFeeReferenceMode $mode,
        public ?string $percent,
    ) {}
}
