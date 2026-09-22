<?php

namespace App\Enums;

/**
 * `holidays.scope` — contrato docs/gap-simplesvet/03-contas-a-pagar-receber-spec.md, campo
 * incorporado ao catálogo `App\Models\Holiday` (item 23) na consolidação com o que seria
 * `company_holidays` (ver migration `2026_10_30_700000_consolidate_holidays_with_company_holidays`).
 *
 * `NATIONAL` com `organization_id` E `professional_id` nulos vale para toda clínica que não
 * tiver sobrescrita própria. `MUNICIPAL`/`COMPANY`/`STATE` sempre têm um dono — feriado da
 * cidade específica ou recesso próprio da empresa não faz sentido "global".
 */
enum HolidayScope: string
{
    case NATIONAL = 'national';
    case STATE = 'state';
    case MUNICIPAL = 'municipal';
    case COMPANY = 'company';

    public function label(): string
    {
        return match ($this) {
            self::NATIONAL => 'Nacional',
            self::STATE => 'Estadual',
            self::MUNICIPAL => 'Municipal',
            self::COMPANY => 'Próprio da empresa',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
