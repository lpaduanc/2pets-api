<?php

namespace App\Rules;

use App\Support\Medical\ChiefComplaintCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `chief_complaint` precisa estar na lista de slugs válidos PARA A ESPÉCIE do pet (contrato
 * §5.4) — "bad_breath_dental" não existe para gato, "hairball" não existe para cão.
 */
final class ValidChiefComplaint implements ValidationRule
{
    public function __construct(private readonly ?string $species) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('A queixa principal é inválida.');

            return;
        }

        if (! in_array($value, ChiefComplaintCatalog::validValuesFor($this->species), true)) {
            $fail('A queixa principal :input não é válida para a espécie deste pet.');
        }
    }
}
