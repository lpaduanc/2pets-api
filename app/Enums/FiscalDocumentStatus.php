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
    // NFC-e emitida offline (tpEmis 9) enquanto a organização está em contingência: já vale como
    // emitida para a venda, mas precisa ser transmitida (spec fase4-fiscal §4).
    case CONTINGENCY = 'contingency';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::PROCESSING => 'Processando',
            self::AUTHORIZED => 'Autorizada',
            self::REJECTED => 'Rejeitada',
            self::CANCELLED => 'Cancelada',
            self::DENIED => 'Denegada',
            self::CONTINGENCY => 'Em contingência',
        };
    }

    /** Aparece em `reports/fiscal-pending` — nunca falha silenciosamente. */
    public function isPending(): bool
    {
        return in_array($this, [self::PENDING, self::PROCESSING, self::REJECTED, self::DENIED, self::CONTINGENCY], true);
    }

    /**
     * Já cumpre a obrigação de emitir da venda: autorizada, ou emitida em contingência (o
     * cliente já levou o DANFE; falta só transmitir).
     */
    public function countsAsIssued(): bool
    {
        return in_array($this, [self::AUTHORIZED, self::CONTINGENCY], true);
    }

    /**
     * Ocupa o lugar da emissão: a venda (ou a devolução) não pode emitir outro documento do mesmo
     * fato enquanto existir um destes. Rejeitado não ocupa — não aconteceu na SEFAZ e a
     * reemissão gera um documento novo (spec fase4-fiscal D11).
     */
    public function blocksReissue(): bool
    {
        return ! in_array($this, [self::CANCELLED, self::DENIED, self::REJECTED], true);
    }

    /**
     * Número que a SEFAZ considera consumido: tudo menos o rejeitado, que vira lacuna a
     * inutilizar (spec fase4-fiscal §3.7).
     */
    public function consumesNumber(): bool
    {
        return $this !== self::REJECTED;
    }

    /** Processando ou em contingência: só a consulta ao provedor decide o resultado final. */
    public function awaitsProvider(): bool
    {
        return in_array($this, [self::PROCESSING, self::CONTINGENCY], true);
    }

    /** @return list<string> */
    public static function valuesWhere(callable $predicate): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), $predicate),
        ));
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
