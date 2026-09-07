<?php

namespace App\DataTransferObjects;

/**
 * CNPJ: 14 dígitos, sem máscara. Ver `DocumentNumber` para a regra de projeto completa.
 */
final readonly class Cnpj extends DocumentNumber
{
    public const DIGIT_COUNT = 14;
}
