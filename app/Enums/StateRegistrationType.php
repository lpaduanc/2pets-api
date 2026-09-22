<?php

namespace App\Enums;

/**
 * `organizations.state_registration_type` / `users.state_registration_type` (cliente PJ) —
 * contrato docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md.
 */
enum StateRegistrationType: string
{
    case TAXPAYER = 'taxpayer';
    case NON_TAXPAYER = 'non_taxpayer';
    case EXEMPT = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::TAXPAYER => 'Contribuinte',
            self::NON_TAXPAYER => 'Não contribuinte',
            self::EXEMPT => 'Isento',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
