<?php

namespace App\Support;

use App\Models\Pet;
use Illuminate\Support\Arr;

/**
 * Invariante de privacidade docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md
 * §B: um profissional que só tem AGENDAMENTO com o pet (sem `PetVetAccess`) nunca pode ver o
 * cadastro clínico completo — só a identidade mínima (nome, espécie, raça, sexo, porte, foto).
 *
 * Devolve uma INSTÂNCIA NOVA de `Pet` (mesma classe, para `PetResource`/dump continuar
 * funcionando sem precisar de um Resource dedicado) com todo campo fora da lista mínima
 * explicitamente nulo — nunca a instância original, que ainda carrega os atributos sensíveis
 * em memória mesmo que o array de saída os omita por acidente em algum outro call site.
 */
final class PetIdentityMinimizer
{
    /**
     * @var list<string>
     */
    private const MINIMAL_FIELDS = [
        'id', 'public_id', 'name', 'species', 'breed', 'breed_id', 'gender', 'size', 'image_url',
    ];

    public static function minimize(Pet $pet): Pet
    {
        $minimal = new Pet;
        $minimal->exists = true;

        // Zera todo campo cadastrável primeiro — assim um campo sensível novo, adicionado a
        // `$fillable` no futuro, nasce nulo aqui por padrão em vez de vazar por omissão.
        $minimal->forceFill(array_fill_keys($pet->getFillable(), null));
        $minimal->forceFill(Arr::only($pet->getAttributes(), self::MINIMAL_FIELDS));

        return $minimal;
    }
}
