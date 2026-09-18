<?php

namespace App\Enums;

/**
 * `sales.status` — contrato docs/gap-simplesvet/01-caixa-pdv.md, filtros da consulta de vendas
 * (`Pago`, `Não pago`, `Orçamento`, `Em atendimento`).
 *
 * `OPEN` é a venda sendo montada no balcão (ainda sem total fechado); `IN_SERVICE` é a conta
 * aberta de um atendimento em curso — o animal está no banho e tosa e a conta ainda recebe
 * item. São estados diferentes porque só o segundo deve aparecer no painel de "contas abertas"
 * da recepção.
 */
enum SaleStatus: string
{
    case OPEN = 'open';
    case IN_SERVICE = 'in_service';
    case PAID = 'paid';
    case UNPAID = 'unpaid';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Em aberto',
            self::IN_SERVICE => 'Em atendimento',
            self::PAID => 'Pago',
            self::UNPAID => 'Não pago',
            self::CANCELLED => 'Cancelado',
        };
    }

    /** Ainda aceita item novo, desconto ou troca de cliente. */
    public function isEditable(): bool
    {
        return in_array($this, [self::OPEN, self::IN_SERVICE, self::UNPAID], true);
    }

    public function isSettled(): bool
    {
        return $this === self::PAID;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
