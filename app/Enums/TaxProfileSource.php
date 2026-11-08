<?php

namespace App\Enums;

/** De onde veio o perfil tributário efetivo de um produto (spec fase4-fiscal §3.3). */
enum TaxProfileSource: string
{
    case PRODUCT = 'product';
    case GROUP = 'group';
    case PARENT_GROUP = 'parent_group';
    case DEFAULT = 'default';

    public function label(): string
    {
        return match ($this) {
            self::PRODUCT => 'Do produto',
            self::GROUP => 'Do grupo',
            self::PARENT_GROUP => 'Do grupo acima',
            self::DEFAULT => 'Perfil padrão',
        };
    }
}
