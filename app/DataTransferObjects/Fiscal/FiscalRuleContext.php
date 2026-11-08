<?php

namespace App\DataTransferObjects\Fiscal;

use App\Enums\FiscalCfopOperation;
use App\Enums\FiscalCfopScope;
use App\Enums\TaxRegime;

/** Para quem, em que regime e em que faixa de CFOP se resolve a regra dos itens de um documento. */
final readonly class FiscalRuleContext
{
    public function __construct(
        public FiscalOwner $owner,
        public ?TaxRegime $regime,
        public FiscalCfopOperation $operation,
        public FiscalCfopScope $scope,
    ) {}

    /** @return array{0: FiscalCfopOperation, 1: FiscalCfopScope} */
    public function slot(): array
    {
        return [$this->operation, $this->scope];
    }
}
