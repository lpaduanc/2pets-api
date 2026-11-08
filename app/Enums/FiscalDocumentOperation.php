<?php

namespace App\Enums;

/**
 * De que fato nasce o documento fiscal (`fiscal_documents.operation`) — spec fase4-fiscal §2.
 * Só `SALE` conta para a pendência de emissão da venda; devolução é documento próprio.
 */
enum FiscalDocumentOperation: string
{
    case SALE = 'sale';
    case SALE_RETURN = 'sale_return';
    case PURCHASE_RETURN = 'purchase_return';

    /** A devolução de venda entra (NF-e de entrada); venda e devolução de compra saem. */
    public function direction(): FiscalDocumentDirection
    {
        return $this === self::SALE_RETURN ? FiscalDocumentDirection::IN : FiscalDocumentDirection::OUT;
    }

    public function cfopOperation(bool $isResale): FiscalCfopOperation
    {
        return match ($this) {
            self::SALE => $isResale ? FiscalCfopOperation::SALE_RESALE : FiscalCfopOperation::SALE_CONSUMER,
            self::SALE_RETURN => FiscalCfopOperation::RETURN_FROM_CUSTOMER,
            self::PURCHASE_RETURN => FiscalCfopOperation::RETURN_TO_SUPPLIER,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
