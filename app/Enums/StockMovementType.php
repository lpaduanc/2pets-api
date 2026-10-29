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
    /**
     * Ajuste de CUSTO, quantidade zero (docs/gap-petflow/PLANO.md, 0.2): diferença entre o custo
     * estimado e o verdadeiro de unidades vendidas sem estoque, compra cancelada depois de
     * vendida e reavaliação manual do médio. Existe para que nenhum valor apareça ou suma do
     * estoque sem uma linha no kardex.
     */
    case COST_VARIANCE_IN = 'cost_variance_in';
    case COST_VARIANCE_OUT = 'cost_variance_out';

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
            self::COST_VARIANCE_IN => 'Ajuste de custo (crédito)',
            self::COST_VARIANCE_OUT => 'Ajuste de custo (débito)',
        };
    }

    public function direction(): StockDirection
    {
        return match ($this) {
            self::PURCHASE_IN, self::RETURN_IN, self::ADJUSTMENT_IN,
            self::TRANSFER_IN, self::OPENING_BALANCE, self::COST_VARIANCE_IN => StockDirection::IN,
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

    /** Movimento só de valor: não mexe no saldo em unidades. */
    public function isCostOnly(): bool
    {
        return $this === self::COST_VARIANCE_IN || $this === self::COST_VARIANCE_OUT;
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
