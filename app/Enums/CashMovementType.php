<?php

namespace App\Enums;

/**
 * `cash_register_movements.type` — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * O sinal do movimento é DERIVADO do tipo (`signFor()`), nunca informado por quem chama: se
 * o valor pudesse chegar negativo, uma sangria lançada com valor positivo por engano viraria
 * uma entrada silenciosa e o fechamento só acusaria a diferença no fim do dia.
 */
enum CashMovementType: string
{
    /** Aporte de troco na abertura ou durante o dia. */
    case SUPPLY = 'supply';

    /** Sangria — retirada de dinheiro da gaveta para o cofre/banco. */
    case WITHDRAWAL = 'withdrawal';

    case SALE_RECEIPT = 'sale_receipt';
    case REFUND = 'refund';
    case ADJUSTMENT = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::SUPPLY => 'Suprimento',
            self::WITHDRAWAL => 'Sangria',
            self::SALE_RECEIPT => 'Recebimento de venda',
            self::REFUND => 'Estorno',
            self::ADJUSTMENT => 'Ajuste',
        };
    }

    /**
     * +1 entra na gaveta, −1 sai. `ADJUSTMENT` é +1 porque o valor do ajuste já chega com o
     * sinal embutido pelo chamador — é o único tipo em que a direção não é inerente ao ato.
     */
    public function signFor(): int
    {
        return match ($this) {
            self::SUPPLY, self::SALE_RECEIPT, self::ADJUSTMENT => 1,
            self::WITHDRAWAL, self::REFUND => -1,
        };
    }

    /** Tipos que o operador lança à mão na tela do caixa. */
    public function isManual(): bool
    {
        return in_array($this, [self::SUPPLY, self::WITHDRAWAL, self::ADJUSTMENT], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
