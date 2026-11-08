<?php

namespace App\Enums;

/** Origem da mercadoria (`det/imposto/ICMS/orig`, tabela A do CST) — spec fase4-fiscal §2. */
enum GoodsOrigin: string
{
    case NATIONAL = '0';
    case FOREIGN_DIRECT_IMPORT = '1';
    case FOREIGN_INTERNAL_MARKET = '2';
    case NATIONAL_IMPORT_CONTENT_OVER_40 = '3';
    case NATIONAL_BASIC_PROCESSES = '4';
    case NATIONAL_IMPORT_CONTENT_UP_TO_40 = '5';
    case FOREIGN_DIRECT_IMPORT_NO_SIMILAR = '6';
    case FOREIGN_INTERNAL_MARKET_NO_SIMILAR = '7';
    case NATIONAL_IMPORT_CONTENT_OVER_70 = '8';

    public function label(): string
    {
        return match ($this) {
            self::NATIONAL => 'Nacional',
            self::FOREIGN_DIRECT_IMPORT => 'Estrangeira — importação direta',
            self::FOREIGN_INTERNAL_MARKET => 'Estrangeira — adquirida no mercado interno',
            self::NATIONAL_IMPORT_CONTENT_OVER_40 => 'Nacional — conteúdo de importação acima de 40%',
            self::NATIONAL_BASIC_PROCESSES => 'Nacional — processos produtivos básicos',
            self::NATIONAL_IMPORT_CONTENT_UP_TO_40 => 'Nacional — conteúdo de importação até 40%',
            self::FOREIGN_DIRECT_IMPORT_NO_SIMILAR => 'Estrangeira — importação direta, sem similar',
            self::FOREIGN_INTERNAL_MARKET_NO_SIMILAR => 'Estrangeira — mercado interno, sem similar',
            self::NATIONAL_IMPORT_CONTENT_OVER_70 => 'Nacional — conteúdo de importação acima de 70%',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
