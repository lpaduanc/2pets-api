<?php

namespace App\Enums;

/**
 * `service_packages.validity_type` — contrato
 * docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md, regra de negócio 3.
 *
 * As três formas existem na prática: banho/tosa costuma ter validade em dias corridos a partir
 * da venda; vacinação de filhote é atrelada a uma data fixa do calendário; day care mensal às
 * vezes é vendido sem prazo de uso nenhum.
 */
enum PackageValidityType: string
{
    case DAYS_FROM_SALE = 'days_from_sale';
    case FIXED_DATE = 'fixed_date';
    case UNLIMITED = 'unlimited';

    public function label(): string
    {
        return match ($this) {
            self::DAYS_FROM_SALE => 'Dias a partir da venda',
            self::FIXED_DATE => 'Data fixa',
            self::UNLIMITED => 'Sem validade',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
