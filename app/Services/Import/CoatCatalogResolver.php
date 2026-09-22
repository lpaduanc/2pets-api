<?php

namespace App\Services\Import;

use App\Models\Coat;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Support\Collection;

/**
 * Casa cada cor/pelagem da planilha com o catálogo de `Coat` do dono (item 23), criando item
 * novo só quando o ator tem `catalog.manage` — regra 4 da spec 26 aplicada ao único catálogo
 * de item 23 que de fato existe por dono (`species`, ao contrário do que a spec supõe, é a
 * `PetSpecies` fixa, sem tabela — ver `PetSpeciesSynonymResolver`). Sem a permissão, o valor
 * digitado é mantido como texto em `coat_colors`, nunca descartado nem bloqueante.
 *
 * Escopo do catálogo via `CommercialScopeResolver` (mesma régua de `CatalogController`) — uma
 * organização com vários funcionários com `catalog.manage` compartilha o mesmo catálogo de
 * pelagem, então o casamento não pode filtrar só pelo `professional_id` de quem subiu o
 * arquivo.
 */
final class CoatCatalogResolver
{
    public function __construct(
        private readonly CatalogSimilarityMatcher $similarityMatcher,
        private readonly CommercialScopeResolver $scope,
    ) {}

    /**
     * @param  list<string>  $rawCoatValues
     * @return list<string> nome canônico do catálogo quando casou, texto original quando não
     */
    public function resolveAll(array $rawCoatValues, User $actor): array
    {
        $catalog = $this->scope->scopeQuery(Coat::query(), $actor)->pluck('name');

        return array_values(array_map(
            fn (string $rawValue): string => $this->resolveOne($rawValue, $catalog, $actor),
            $rawCoatValues,
        ));
    }

    /**
     * @param  Collection<int, string>  $catalog
     */
    private function resolveOne(string $rawValue, Collection $catalog, User $actor): string
    {
        $trimmed = trim($rawValue);
        $matched = $this->similarityMatcher->bestMatch($trimmed, $catalog->all());

        if ($matched !== null) {
            return $matched;
        }

        if ($actor->can('catalog.manage')) {
            Coat::create([...$this->scope->ownershipFor($actor), 'name' => $trimmed, 'active' => true]);
        }

        return $trimmed;
    }
}
