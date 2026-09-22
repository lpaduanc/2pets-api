<?php

namespace App\DataTransferObjects\Fiscal;

use App\Enums\FiscalDocumentStatus;

/**
 * Resposta de um `FiscalProviderGateway` — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md. O mesmo formato serve para
 * emitir, consultar e cancelar: cada operação devolve o estado atual do documento no provedor.
 */
final readonly class FiscalProviderResult
{
    public function __construct(
        public FiscalDocumentStatus $status,
        public ?string $accessKey = null,
        public ?string $providerId = null,
        public ?string $rejectionReason = null,
        public ?string $protocol = null,
    ) {}
}
