<?php

namespace App\Enums;

/**
 * As 10 faixas do SimplesVet — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`. Calculada por
 * `ClientLifecycleService::classify()` a partir do "marco de retorno" (regra de negócio 2 da
 * spec: `GREATEST(último Appointment completed, última Invoice paga, última Sale paga)`).
 */
enum ClientLifecycleStage: string
{
    /** Marco de retorno dentro do último mês. */
    case RETURNED_RECENTLY = 'returned_recently';

    /** Entre 1 e 3 meses sem retorno. */
    case QUIET_1_3M = 'quiet_1_3m';

    /** Entre 3 e 6 meses sem retorno. */
    case QUIET_3_6M = 'quiet_3_6m';

    /** Entre 6 meses e 1 ano sem retorno. */
    case QUIET_6_12M = 'quiet_6_12m';

    /** Entre 1 e 2 anos sem retorno. */
    case NEEDS_ATTENTION_1_2Y = 'needs_attention_1_2y';

    /** Entre 2 e 3 anos sem retorno. */
    case NEEDS_ATTENTION_2_3Y = 'needs_attention_2_3y';

    /** Entre 3 e 5 anos sem retorno. */
    case CHURNED_3_5Y = 'churned_3_5y';

    /** Mais de 5 anos sem retorno. */
    case CHURNED_5Y_PLUS = 'churned_5y_plus';

    /** Nunca teve um marco de retorno (nenhum atendimento/fatura/venda paga). */
    case NO_PURCHASE_YET = 'no_purchase_yet';

    public function label(): string
    {
        return match ($this) {
            self::RETURNED_RECENTLY => 'Retornou recentemente',
            self::QUIET_1_3M => 'Quieto (1-3 meses)',
            self::QUIET_3_6M => 'Quieto (3-6 meses)',
            self::QUIET_6_12M => 'Quieto (6-12 meses)',
            self::NEEDS_ATTENTION_1_2Y => 'Precisa de atenção (1-2 anos)',
            self::NEEDS_ATTENTION_2_3Y => 'Precisa de atenção (2-3 anos)',
            self::CHURNED_3_5Y => 'Perdido (3-5 anos)',
            self::CHURNED_5Y_PLUS => 'Perdido (mais de 5 anos)',
            self::NO_PURCHASE_YET => 'Nunca comprou',
        };
    }
}
