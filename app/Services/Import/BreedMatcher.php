<?php

namespace App\Services\Import;

use App\Enums\PetSpecies;
use App\Models\Breed;

/**
 * Casa o texto livre de raça com o catálogo GLOBAL de `Breed` (item 26 do backlog
 * gap-simplesvet) — `Breed` não tem `organization_id`/`professional_id` (confirmado lendo o
 * model), então, ao contrário do que a spec original sugere para "espécie/raça", este
 * casamento nunca CRIA linha nova de catálogo a partir de uma planilha de um dono só: isso
 * poluiria a taxonomia global para todas as organizações a partir do erro de digitação de uma
 * única planilha. Raça sem casamento fica como texto livre em `pets.breed` (coluna que já
 * existe para isso) — nunca bloqueia a linha (regra 4 da spec).
 */
final class BreedMatcher
{
    public function __construct(private readonly CatalogSimilarityMatcher $similarityMatcher) {}

    /**
     * @return array{breed_id: ?int, breed_name: ?string}
     */
    public function match(string $rawBreed, PetSpecies $species): array
    {
        $trimmed = trim($rawBreed);
        if ($trimmed === '') {
            return ['breed_id' => null, 'breed_name' => null];
        }

        $breeds = Breed::query()->where('species', $species->value)->pluck('name', 'id');
        $matchedName = $this->similarityMatcher->bestMatch($trimmed, $breeds->values()->all());

        if ($matchedName === null) {
            return ['breed_id' => null, 'breed_name' => $trimmed];
        }

        $breedId = $breeds->search($matchedName);

        return ['breed_id' => $breedId === false ? null : (int) $breedId, 'breed_name' => $matchedName];
    }
}
