<?php

namespace App\Enums;

/**
 * `stock_movements.type` — contrato docs/gap-simplesvet/07-estoque-movimentacoes-inventario-analise.md.
 *
 * O sentido (entrada/saída) é DERIVADO do tipo, nunca informado por quem chama — mesma
 * disciplina de `CashMovementType::signFor()`: uma "perda" lançada como entrada por engano
 * viraria estoque fantasma.
 */
enum StockMovementType: string
{
    case PURCHASE_IN = 'purchase_in';
    case SALE_OUT = 'sale_out';
    /** Devolução de cliente (ou estorno de venda cancelada) — volta para a prateleira. */
    case RETURN_IN = 'return_in';
    /** Devolução ao fornecedor, ou estorno de compra cancelada. */
    case RETURN_OUT = 'return_out';
    case ADJUSTMENT_IN = 'adjustment_in';
    case ADJUSTMENT_OUT = 'adjustment_out';
    case INTERNAL_USE = 'internal_use';
    case LOSS = 'loss';
    case EXPIRY = 'expiry';
    case TRANSFER_IN = 'transfer_in';
    case TRANSFER_OUT = 'transfer_out';
    case OPENING_BALANCE = 'opening_balance';

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE_IN => 'Compra',
            self::SALE_OUT => 'Venda',
            self::RETURN_IN => 'Devolução de cliente',
            self::RETURN_OUT => 'Devolução ao fornecedor',
            self::ADJUSTMENT_IN => 'Ajuste (entrada)',
            self::ADJUSTMENT_OUT => 'Ajuste (saída)',
            self::INTERNAL_USE => 'Uso interno',
            self::LOSS => 'Perda / quebra',
            self::EXPIRY => 'Vencimento',
            self::TRANSFER_IN => 'Transferência (entrada)',
            self::TRANSFER_OUT => 'Transferência (saída)',
            self::OPENING_BALANCE => 'Saldo inicial',
        };
    }

    public function direction(): StockDirection
    {
        return match ($this) {
            self::PURCHASE_IN, self::RETURN_IN, self::ADJUSTMENT_IN,
            self::TRANSFER_IN, self::OPENING_BALANCE => StockDirection::IN,
            default => StockDirection::OUT,
        };
    }

    /**
     * Tipos que o usuário lança à mão (`POST stock-movements`). Venda, compra, devolução e
     * inventário têm fluxo próprio e não podem ser forjados por fora dele.
     */
    public function isManual(): bool
    {
        return in_array($this, self::manual(), true);
    }

    /** "Outras saídas de estoque" do SimplesVet — saída que não é venda. */
    public function isOtherExit(): bool
    {
        return in_array($this, [self::INTERNAL_USE, self::LOSS, self::EXPIRY, self::ADJUSTMENT_OUT], true);
    }

    /** @return list<self> */
    public static function manual(): array
    {
        return [self::ADJUSTMENT_IN, self::ADJUSTMENT_OUT, self::INTERNAL_USE, self::LOSS, self::EXPIRY];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
