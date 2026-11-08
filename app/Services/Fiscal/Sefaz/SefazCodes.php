<?php

namespace App\Services\Fiscal\Sefaz;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentOperation;
use App\Enums\FiscalEnvironment;
use App\Enums\PaymentMethodKind;
use App\Enums\StateRegistrationType;
use App\Enums\TaxRegime;

/**
 * Tabelas de códigos do layout 4.00 da NF-e usadas no mapeamento do payload neutro — spec
 * docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §5 (tabela payload → tags).
 */
final class SefazCodes
{
    /** Regra de homologação (NT 2011.004 / regras 598 e 373): nome do destinatário e 1º item da NFC-e. */
    public const HOMOLOGATION_RECIPIENT = 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';

    public const HOMOLOGATION_NFCE_FIRST_ITEM = 'NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';

    public const PAYMENT_NONE = '90';

    public const PAYMENT_OTHER = '99';

    /** tPag por natureza da forma de pagamento do balcão (`PaymentMethodKind`). */
    private const PAYMENT_TYPES = [
        PaymentMethodKind::CASH->value => '01',
        PaymentMethodKind::CHECK->value => '02',
        PaymentMethodKind::CREDIT_CARD->value => '03',
        PaymentMethodKind::DEBIT_CARD->value => '04',
        PaymentMethodKind::ACCOUNT_CREDIT->value => '05',
        PaymentMethodKind::BOLETO->value => '15',
        // Pix do balcão é QR estático da loja ou da maquininha sem integração: "PIX estático".
        PaymentMethodKind::PIX->value => '20',
        // Cobrança recorrente por link é cartão de crédito sem TEF.
        PaymentMethodKind::RECURRING_LINK->value => '03',
    ];

    /** Formas que exigem o grupo `card` (tpIntegra 2 = maquininha não integrada ao sistema). */
    public const CARD_PAYMENT_TYPES = ['03', '04'];

    private const OPERATION_NATURES = [
        FiscalDocumentOperation::SALE->value => 'VENDA DE MERCADORIA',
        FiscalDocumentOperation::SALE_RETURN->value => 'DEVOLUCAO DE VENDA',
        FiscalDocumentOperation::PURCHASE_RETURN->value => 'DEVOLUCAO DE COMPRA',
    ];

    public static function environment(FiscalEnvironment $environment): int
    {
        return $environment === FiscalEnvironment::PRODUCTION ? 1 : 2;
    }

    /** CRT: 1 Simples Nacional, 3 Regime Normal, 4 MEI. */
    public static function taxRegime(?string $regime): string
    {
        return match ($regime) {
            TaxRegime::SIMPLES_NACIONAL->value => '1',
            TaxRegime::MEI->value => '4',
            default => '3',
        };
    }

    /** indIEDest: 1 contribuinte, 2 isento, 9 não contribuinte (NFC-e é sempre 9). */
    public static function recipientTaxpayer(?string $type, bool $isConsumerInvoice): string
    {
        if ($isConsumerInvoice) {
            return '9';
        }

        return match ($type) {
            StateRegistrationType::TAXPAYER->value => '1',
            StateRegistrationType::EXEMPT->value => '2',
            default => '9',
        };
    }

    public static function paymentType(?string $kind): string
    {
        return self::PAYMENT_TYPES[(string) $kind] ?? self::PAYMENT_OTHER;
    }

    /** finNFe: 1 normal, 4 devolução. */
    public static function purpose(FiscalDocumentOperation $operation): string
    {
        return $operation === FiscalDocumentOperation::SALE ? '1' : '4';
    }

    /** tpNF: 0 entrada, 1 saída. */
    public static function direction(FiscalDocumentDirection $direction): string
    {
        return $direction === FiscalDocumentDirection::IN ? '0' : '1';
    }

    public static function operationNature(FiscalDocumentOperation $operation): string
    {
        return self::OPERATION_NATURES[$operation->value];
    }
}
