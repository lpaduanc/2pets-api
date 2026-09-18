<?php

namespace App\Enums;

/**
 * Ciclo de vida do caixa — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * Quatro estados, e não dois, porque FECHAR e ENCERRAR são atos de pessoas diferentes:
 * o operador fecha (conta a gaveta e informa o que achou), o responsável encerra (confere a
 * diferença e aceita). `UNDER_REVIEW` é a devolução — o encerramento recusado que volta para
 * o operador explicar, sem reabrir o caixa para novos movimentos.
 */
enum CashRegisterStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case SETTLED = 'settled';
    case UNDER_REVIEW = 'under_review';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Aberto',
            self::CLOSED => 'Fechado',
            self::SETTLED => 'Encerrado',
            self::UNDER_REVIEW => 'Em revisão',
        };
    }

    /**
     * Único estado que aceita venda, suprimento ou sangria. Critério de aceite do doc 01:
     * "Caixa `closed` rejeita novo movimento com 422".
     */
    public function acceptsMovements(): bool
    {
        return $this === self::OPEN;
    }

    /** Já foi contado; falta o aceite do responsável. */
    public function awaitsSettlement(): bool
    {
        return in_array($this, [self::CLOSED, self::UNDER_REVIEW], true);
    }

    public function isFinal(): bool
    {
        return $this === self::SETTLED;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
