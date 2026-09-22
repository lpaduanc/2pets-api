<?php

namespace App\Enums;

/**
 * `fiscal_documents.kind` — contrato docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md.
 * Roteamento de qual venda gera qual documento fica em `App\Enums\FiscalOperation` (produto) e
 * "todo serviço é NFS-e" (regra fixa, não depende de `fiscal_operation`).
 */
enum FiscalDocumentKind: string
{
    case NFE = 'nfe';
    case NFCE = 'nfce';
    case NFSE = 'nfse';

    public function label(): string
    {
        return match ($this) {
            self::NFE => 'NF-e',
            self::NFCE => 'NFC-e',
            self::NFSE => 'NFS-e',
        };
    }
}
