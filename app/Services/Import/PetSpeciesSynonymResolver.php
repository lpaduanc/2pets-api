<?php

namespace App\Services\Import;

use App\Enums\PetSpecies;
use Illuminate\Support\Str;

/**
 * Casa o texto livre de espécie de uma planilha legada com `PetSpecies` (item 26 do backlog
 * gap-simplesvet). Regra 4 da spec: espécie não reconhecida nunca vira erro bloqueante — sem
 * sinônimo que bata, a linha usa `PetSpecies::OTHER`, nunca fica sem espécie.
 *
 * Diverge da spec num ponto medido no código: ela descreve um "catálogo de espécie" (item 23,
 * `GET catalogs/species`) com criação de item novo gated por `catalog.manage`. Esse catálogo
 * não existe — `pets.species` é a `PetSpecies` fixa (7 valores), sem tabela por dono. Este
 * resolvedor cobre o que existe de fato: sinônimo → enum, sem criação de valor novo.
 */
final class PetSpeciesSynonymResolver
{
    /**
     * Alias normalizado (minúsculo, sem acento) => `PetSpecies`.
     *
     * @var array<string, string>
     */
    private const SYNONYMS = [
        'dog' => 'dog', 'cao' => 'dog', 'cachorro' => 'dog', 'cachorra' => 'dog',
        'canino' => 'dog', 'canina' => 'dog', 'cadela' => 'dog',
        'cat' => 'cat', 'gato' => 'cat', 'gata' => 'cat', 'felino' => 'cat', 'felina' => 'cat',
        'bird' => 'bird', 'ave' => 'bird', 'passaro' => 'bird', 'calopsita' => 'bird', 'periquito' => 'bird',
        'reptile' => 'reptile', 'reptil' => 'reptile', 'lagarto' => 'reptile', 'tartaruga' => 'reptile', 'jabuti' => 'reptile',
        'rodent' => 'rodent', 'roedor' => 'rodent', 'hamster' => 'rodent', 'coelho' => 'rodent', 'porquinho da india' => 'rodent',
        'fish' => 'fish', 'peixe' => 'fish',
        'other' => 'other', 'outro' => 'other', 'outros' => 'other', 'exotico' => 'other',
    ];

    /**
     * Nunca `null`: `PetSpecies::OTHER` é o piso, consistente com a regra 4 ("nunca vira erro
     * bloqueante"). O chamador decide se quer avisar o usuário que caiu em "outro".
     */
    public function resolve(string $rawSpecies): PetSpecies
    {
        $normalized = $this->normalize($rawSpecies);

        $value = self::SYNONYMS[$normalized] ?? null;

        return $value === null ? PetSpecies::OTHER : PetSpecies::from($value);
    }

    /** Verdadeiro só quando o texto bateu com um sinônimo conhecido, não com o piso `other`. */
    public function wasRecognized(string $rawSpecies): bool
    {
        return array_key_exists($this->normalize($rawSpecies), self::SYNONYMS);
    }

    private function normalize(string $rawSpecies): string
    {
        return Str::of($rawSpecies)->lower()->ascii()->trim()->toString();
    }
}
