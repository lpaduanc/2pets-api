<?php

namespace App\DataTransferObjects\Reports;

use Carbon\CarbonInterface;

/**
 * Filtros de `ClinicEventFeedService::forTeam()` agrupados num único objeto — evita estourar
 * o limite de 4 parâmetros por método nos 4 construtores de query privados do serviço.
 */
final readonly class ClinicEventFeedFilters
{
    public function __construct(
        public ?int $clientId,
        public ?string $species,
        public CarbonInterface $from,
        public CarbonInterface $to,
        public bool $includeContact,
    ) {}
}
