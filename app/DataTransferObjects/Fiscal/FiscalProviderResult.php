<?php

namespace App\DataTransferObjects\Fiscal;

use App\Enums\FiscalDocumentStatus;

/**
 * Resposta de um `FiscalProviderGateway` — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md e docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §5. O mesmo formato serve para emitir, consultar, cancelar e
 * inutilizar: cada operação devolve o estado atual no provedor.
 *
 * `number`/`series`: só quando o provedor numera (NFS-e no Emissor Nacional); NF-e/NFC-e usam o
 * número que nós reservamos. `notice`: aviso informativo do provedor (não é erro).
 */
final readonly class FiscalProviderResult
{
    public function __construct(
        public FiscalDocumentStatus $status,
        public ?string $accessKey = null,
        public ?string $providerId = null,
        public ?string $rejectionReason = null,
        public ?string $protocol = null,
        public ?int $number = null,
        public ?string $series = null,
        public ?string $notice = null,
    ) {}
}
