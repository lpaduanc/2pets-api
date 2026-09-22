<?php

namespace App\DataTransferObjects\Reports;

use Carbon\Carbon;

/**
 * Filtros de `App\Services\Reports\ImmunizationAdherenceService::summarize()` — contrato
 * `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`. Agrupados num objeto só para não
 * estourar o limite de 4 parâmetros por método (eram 5 soltos antes desta extração).
 */
final readonly class ImmunizationPanelFilters
{
    public function __construct(
        public string $type,
        public ?string $status,
        public ?Carbon $from,
        public ?Carbon $to,
        public bool $includeContact,
    ) {}
}
