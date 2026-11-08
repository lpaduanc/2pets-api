<?php

namespace App\DataTransferObjects\Fiscal;

/**
 * O que o driver fiscal sabe fazer — spec fase4-fiscal §5. Quem chama confere ANTES (422 claro)
 * em vez de o driver lançar "não suportado" (LSP: todo método do contrato funciona).
 */
final readonly class FiscalProviderCapabilities
{
    public function __construct(
        public bool $invalidation,
        public bool $nfceOfflineContingency,
        public bool $returnInvoices,
    ) {}

    /** @return array{invalidation: bool, nfce_offline_contingency: bool, return_invoices: bool} */
    public function toArray(): array
    {
        return [
            'invalidation' => $this->invalidation,
            'nfce_offline_contingency' => $this->nfceOfflineContingency,
            'return_invoices' => $this->returnInvoices,
        ];
    }
}
