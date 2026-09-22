<?php

namespace App\DataTransferObjects\Reports;

/**
 * Filtros de `BirthdayService::inPeriod()` — contrato
 * `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`. `includeContact` reflete a
 * permissão `clients.contact.view-bulk` (regra de negócio 4): quando falsa, telefone/e-mail
 * do tutor não aparecem na resposta, mesmo que o registro exista.
 */
final readonly class BirthdayFilters
{
    public function __construct(
        public \DateTimeInterface $from,
        public \DateTimeInterface $to,
        public string $scope,
        public bool $includeContact,
    ) {}
}
