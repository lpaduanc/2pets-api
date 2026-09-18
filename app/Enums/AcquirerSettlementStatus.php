<?php

namespace App\Enums;

/**
 * `acquirer_settlements.status` — contrato docs/gap-simplesvet/04, totalizadores da tela de
 * conciliação de cartões (`Todos` / `Conciliados` / `Não conciliados`).
 *
 * `DIVERGENT` é o terceiro estado que a tela não mostra como aba mas o critério de aceite
 * exige: depósito cujo líquido não fecha com a soma dos recebimentos vinculados. Sem ele, a
 * única saída seria conciliar errado ou deixar pendente para sempre.
 */
enum AcquirerSettlementStatus: string
{
    case PENDING = 'pending';
    case RECONCILED = 'reconciled';
    case DIVERGENT = 'divergent';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Não conciliado',
            self::RECONCILED => 'Conciliado',
            self::DIVERGENT => 'Divergente',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
