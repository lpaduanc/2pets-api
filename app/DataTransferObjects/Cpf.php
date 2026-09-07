<?php

namespace App\DataTransferObjects;

/**
 * CPF: 11 dígitos, sem máscara. Ver `DocumentNumber` para a regra de projeto completa.
 */
final readonly class Cpf extends DocumentNumber
{
    public const DIGIT_COUNT = 11;
}
