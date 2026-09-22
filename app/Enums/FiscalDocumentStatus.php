<?php

namespace App\Enums;

/**
 * `fiscal_documents.status` — contrato docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md.
 * `PENDING` nasce e some rápido com o driver padrão (`LogFiscalProviderGateway` resolve na
 * hora); um provedor real assíncrono é quem justificaria `PROCESSING` durar além de um
 * request — ver `FiscalProviderGateway`.
 */
enum FiscalDocumentStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case AUTHORIZED = 'authorized';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case DENIED = 'denied';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::PROCESSING => 'Processando',
            self::AUTHORIZED => 'Autorizada',
            self::REJECTED => 'Rejeitada',
            self::CANCELLED => 'Cancelada',
            self::DENIED => 'Denegada',
        };
    }

    /** Aparece em `reports/fiscal-pending` — nunca falha silenciosamente. */
    public function isPending(): bool
    {
        return in_array($this, [self::PENDING, self::PROCESSING, self::REJECTED, self::DENIED], true);
    }

    public function canBeCancelled(): bool
    {
        return $this === self::AUTHORIZED;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
