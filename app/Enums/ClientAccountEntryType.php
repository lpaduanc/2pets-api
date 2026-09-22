<?php

namespace App\Enums;

/**
 * `client_account_entries.type` — contrato docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md.
 *
 * `SALE_DEBIT` e `ADJUSTMENT_DEBIT` andam com `direction = debit`; `ADVANCE_CREDIT`,
 * `PACKAGE_CREDIT`, `LOYALTY_CREDIT` e `ADJUSTMENT_CREDIT` andam com `direction = credit`.
 * `REFUND_DEBIT` (estorno de um recebimento em `account_credit`, ao cancelar a venda) é
 * `direction = credit` apesar do nome — ver `ClientAccountEntryDirection`.
 *
 * `PAYMENT_CREDIT` é o único tipo com DUAS direções legítimas conforme o contexto:
 * `direction = debit` quando `SaleService::registerReceipt()` CONSOME saldo credor existente
 * (forma de pagamento `account_credit` — ver `ClientAccountService::consumeCredit()`);
 * `direction = credit` quando um operador registra manualmente que o cliente PAGOU uma dívida
 * de fiado em dinheiro/Pix (`POST clients/{client}/account-entries`, quitando um `SALE_DEBIT`
 * anterior) — `ClientAccountService::recordManualEntry()` sempre grava esta segunda direção.
 */
enum ClientAccountEntryType: string
{
    case SALE_DEBIT = 'sale_debit';
    case PAYMENT_CREDIT = 'payment_credit';
    case ADVANCE_CREDIT = 'advance_credit';
    case REFUND_DEBIT = 'refund_debit';
    case ADJUSTMENT_DEBIT = 'adjustment_debit';
    case ADJUSTMENT_CREDIT = 'adjustment_credit';
    case PACKAGE_CREDIT = 'package_credit';
    case LOYALTY_CREDIT = 'loyalty_credit';

    public function label(): string
    {
        return match ($this) {
            self::SALE_DEBIT => 'Venda a prazo',
            self::PAYMENT_CREDIT => 'Pagamento com saldo',
            self::ADVANCE_CREDIT => 'Adiantamento',
            self::REFUND_DEBIT => 'Estorno de pagamento com saldo',
            self::ADJUSTMENT_DEBIT => 'Ajuste (débito)',
            self::ADJUSTMENT_CREDIT => 'Ajuste (crédito)',
            self::PACKAGE_CREDIT => 'Crédito de pacote',
            self::LOYALTY_CREDIT => 'Crédito de fidelidade',
        };
    }

    /**
     * Tipos que o endpoint manual (`POST clients/{client}/account-entries`) aceita.
     * `PAYMENT_CREDIT` aqui é sempre a quitação manual de um fiado (`direction = credit`) —
     * a outra direção (`debit`, consumo de saldo) só nasce por efeito colateral do
     * `SaleService`, nunca por este endpoint. `SALE_DEBIT`/`REFUND_DEBIT` idem: só automáticos.
     * `PACKAGE_CREDIT`/`LOYALTY_CREDIT` são ponto de extensão dos itens 09/10.
     *
     * @return list<self>
     */
    public static function manuallyRecordable(): array
    {
        return [self::ADVANCE_CREDIT, self::PAYMENT_CREDIT, self::ADJUSTMENT_DEBIT, self::ADJUSTMENT_CREDIT];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
