<?php

namespace App\Rules;

use App\Services\Professional\SpecialtyCatalogIndex;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A especialidade declarada precisa existir no catálogo `specialties`.
 *
 * Fecha o caminho de ESCRITA que deixava `professionals.specialties` virar texto livre: antes
 * disso a regra era `['nullable', 'array']` e qualquer string entrava, o que produziu na
 * mesma base `"Clínica Geral"`, `"clinica_geral"` e `"general"` como se fossem conceitos
 * diferentes — e um filtro de busca que não encontrava nenhum deles de forma confiável.
 *
 * Aceita também os slugs em inglês do formulário do app (`SpecialtyAliasCatalog`): sem eles
 * esta regra fechava o cadastro de veterinário inteiro, porque o cliente nunca mandou a
 * grafia do catálogo. Alias resolve para a MESMA linha — não afrouxa nada.
 *
 * Aceita qualquer grafia que NORMALIZE para um nome do catálogo (ver `SpecialtyCatalogIndex`),
 * porque acento, caixa, `/` e `_` são diferenças de apresentação, não de conceito. O que ela
 * rejeita é conceito inexistente — e aí 422 é a resposta certa: devolver 200 e gravar um
 * rótulo que nenhuma busca encontra é pior para o profissional do que recusar o cadastro.
 */
final class ValidSpecialty implements ValidationRule
{
    public function __construct(private readonly SpecialtyCatalogIndex $catalog) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->catalog->isInCatalog($value)) {
            $fail('A especialidade :input não está no catálogo de especialidades veterinárias.');
        }
    }
}
