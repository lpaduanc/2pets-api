<?php

namespace App\DataTransferObjects\Finance;

use Carbon\CarbonImmutable;

/**
 * Uma parcela calculada de uma série de `financial_entries` — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md. Value object puro para
 * `FinancialEntryService` não passar 6+ parâmetros posicionais para `store()`.
 */
final readonly class PlannedFinancialInstallment
{
    public function __construct(
        public ?string $seriesId,
        public int $number,
        public int $total,
        public CarbonImmutable $dueDate,
        public float $amount,
    ) {}
}
