<?php

namespace App\Enums;

/**
 * O que um `Payment` representa para a fatura (`payments.purpose`). Contrato
 * docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §3-bis.2.
 *
 * `SETTLEMENT` é o pagamento que efetivamente fecha a fatura (no máximo um por `Invoice`,
 * mesma garantia de sempre). `ADVANCE` é um recebimento parcial registrado — nunca fecha a
 * fatura sozinho, e é restrito à internação (`PaymentService::assertAdvanceAllowed`).
 * Default da coluna é `settlement`: toda linha de pagamento que já existia no sistema antes
 * desta coluna continua se comportando exatamente como hoje.
 */
enum PaymentPurpose: string
{
    case SETTLEMENT = 'settlement';
    case ADVANCE = 'advance';

    public function label(): string
    {
        return match ($this) {
            self::SETTLEMENT => 'Acerto final',
            self::ADVANCE => 'Adiantamento',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
