<?php

namespace App\Enums;

/**
 * Por onde o dinheiro de uma fatura pago passou (`invoices.payment_channel`). Contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §6/Invariante 5.
 *
 * Sempre gravado explicitamente no momento do pagamento, nunca inferido — nenhuma leitura
 * futura (relatório, comissão, conciliação) pode tratar as duas coisas como equivalentes.
 */
enum PaymentChannel: string
{
    case PLATFORM_GATEWAY = 'platform_gateway';
    case MANUAL_OFFLINE = 'manual_offline';

    public function label(): string
    {
        return match ($this) {
            self::PLATFORM_GATEWAY => 'Gateway da plataforma',
            self::MANUAL_OFFLINE => 'Recebido por fora (declarado)',
        };
    }
}
