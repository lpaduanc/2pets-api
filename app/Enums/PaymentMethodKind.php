<?php

namespace App\Enums;

/**
 * `payment_methods.kind` — a NATUREZA da forma de pagamento, separada do ADQUIRENTE.
 *
 * O SimplesVet cadastra "Maquininha Cielo", "Maquininha Rede", "Maquininha Mercado Pago" como
 * formas distintas, e está certo: a taxa e o prazo de liquidação são de cada adquirente, não do
 * "cartão de crédito" em abstrato. Mas o RELATÓRIO precisa somar tudo que é cartão. Por isso
 * `kind` (o que é) e `acquirer` (por onde passou) são colunas separadas — juntar as duas numa
 * string só obrigaria o BI a fazer `LIKE '%cartão%'`.
 *
 * Não confundir com `App\Enums\PaymentMethod`, que descreve o pagamento do MARKETPLACE via
 * gateway (Stripe/Mercado Pago). Aqui é o balcão da clínica.
 */
enum PaymentMethodKind: string
{
    case CASH = 'cash';
    case PIX = 'pix';
    case BOLETO = 'boleto';
    case CHECK = 'check';
    case CREDIT_CARD = 'credit_card';
    case DEBIT_CARD = 'debit_card';
    case RECURRING_LINK = 'recurring_link';
    case VOUCHER = 'voucher';
    case ACCOUNT_CREDIT = 'account_credit';

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Dinheiro',
            self::PIX => 'Pix',
            self::BOLETO => 'Boleto',
            self::CHECK => 'Cheque',
            self::CREDIT_CARD => 'Cartão de crédito',
            self::DEBIT_CARD => 'Cartão de débito',
            self::RECURRING_LINK => 'Cobrança recorrente por link',
            self::VOUCHER => 'Vale / convênio',
            self::ACCOUNT_CREDIT => 'Conta corrente do cliente',
        };
    }

    /**
     * Entra na conferência da GAVETA no fechamento do caixa (doc 01). Só dinheiro: cartão e
     * Pix não são contados à mão, são conferidos contra o extrato da adquirente (este doc).
     */
    public function isPhysicalCash(): bool
    {
        return $this === self::CASH;
    }

    /** Passa por adquirente e portanto gera previsão de depósito e taxa. */
    public function settlesThroughAcquirer(): bool
    {
        return in_array($this, [self::CREDIT_CARD, self::DEBIT_CARD, self::RECURRING_LINK], true);
    }

    public function allowsInstallments(): bool
    {
        return in_array($this, [self::CREDIT_CARD, self::BOLETO, self::RECURRING_LINK], true);
    }

    /**
     * Não é dinheiro que entra: é dívida que o cliente assume (doc 11). Recebimento assim NÃO
     * movimenta conta nem caixa — movimenta o saldo do cliente. Marcado aqui para que o doc 11
     * encontre a regra pronta.
     */
    public function isDeferredToClientAccount(): bool
    {
        return $this === self::ACCOUNT_CREDIT;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
