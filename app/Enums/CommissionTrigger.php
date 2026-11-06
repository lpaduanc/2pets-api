<?php

namespace App\Enums;

/**
 * `commission_rules.trigger` — quando a comissão de um item NASCE (PLANO 2.6). Substitui o antigo
 * `only_when_received`, que nunca foi honrado como `false`.
 *
 * O gatilho define a DATA do fato que põe o item num fechamento (a data de corte do fechamento é
 * comparada com ela). Ele NÃO dispensa o piso comum da regra de negócio 1 do doc 09 ("comissão
 * só sobre venda efetivamente recebida"): em todos os três, a venda precisa estar paga, com ao
 * menos um recebimento valendo, no momento do fechamento — ver `CommissionEligibility`.
 */
enum CommissionTrigger: string
{
    case ON_SALE = 'on_sale';
    case ON_RECEIPT = 'on_receipt';
    case ON_EXECUTION = 'on_execution';

    public function label(): string
    {
        return match ($this) {
            self::ON_SALE => 'Na venda',
            self::ON_RECEIPT => 'No recebimento',
            self::ON_EXECUTION => 'Na execução do serviço',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
