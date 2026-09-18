<?php

namespace App\Enums;

/**
 * `products.purpose` — para que serve o item no negócio, conforme o campo `Propósito*` do
 * cadastro de produto do SimplesVet (docs/gap-simplesvet/08-produtos-precificacao-lista-precos.md).
 *
 * Não é categoria nem grupo: separa o que se REVENDE do que se CONSOME. Item de uso interno
 * (luva, seringa, algodão) nunca deve aparecer no catálogo do PDV, mas continua controlando
 * estoque e entrando na compra — é essa a distinção que a coluna carrega.
 */
enum ProductPurpose: string
{
    case RESALE = 'resale';
    case INTERNAL_USE = 'internal_use';
    case CONSUMABLE = 'consumable';

    public function label(): string
    {
        return match ($this) {
            self::RESALE => 'Revenda',
            self::INTERNAL_USE => 'Uso interno',
            self::CONSUMABLE => 'Consumível',
        };
    }

    /** Só item de revenda entra no catálogo de balcão do PDV (doc 01). */
    public function isSellable(): bool
    {
        return $this === self::RESALE;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
