<?php

namespace App\Enums;

/**
 * `partner_payouts.status` — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md, regra de negócio 7:
 * repasse a parceiro terceiro (vet volante/diarista sem vínculo CLT), conceitualmente
 * diferente da comissão de staff — registro e conciliação manuais, sem regra automática.
 */
enum PartnerPayoutStatus: string
{
    case PENDING = 'pending';
    case RECONCILED = 'reconciled';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::RECONCILED => 'Conciliado',
            self::CANCELLED => 'Cancelado',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
