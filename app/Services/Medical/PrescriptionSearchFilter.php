<?php

namespace App\Services\Medical;

use App\Models\Prescription;
use App\Services\Search\FuzzyMatchExpressionBuilder;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Busca textual da lista de prescrições do profissional: nome do pet, nome do tutor e
 * medicamento prescrito.
 *
 * Indexabilidade (mesmas duas regras de `PatientSearchFilter`, ver
 * `.claude/agent-memory/backend-specialist/busca-fuzzy-fase5.md`):
 *
 *   1. Subconsultas NÃO correlacionadas (`pet_id IN (SELECT …)`), nunca `whereHas`. Um EXISTS
 *      correlacionado pela PK obriga o Postgres a resolver linha a linha e o índice GIN trigram
 *      nunca é usado.
 *   2. Cada lado do OR fica sobre UMA tabela só — `BitmapOr` entre índices de tabelas
 *      diferentes não existe no Postgres. Pet e tutor viram dois `IN` sobre `pets`, resolvidos
 *      por `idx_pets_name_unaccent_trgm` e `idx_users_name_unaccent_trgm`.
 *
 * O ramo do medicamento é o único sem índice, e isso é aceitável: ele só roda depois do
 * `professional_id = ?` (índice B-tree), portanto sobre as receitas do próprio profissional.
 * A comparação é feita em `::jsonb::text` — o cast `array` do model grava com escape unicode
 * (`Ração`), e só a normalização do `jsonb` devolve o texto legível para o ILIKE
 * casar. `::jsonb::text` também é seguro para qualquer formato de JSON armazenado, ao
 * contrário de `jsonb_array_elements()`, que estoura em linha que não seja array.
 */
final class PrescriptionSearchFilter
{
    /** Abaixo de 3 caracteres o pg_trgm não extrai um trigrama completo e a busca vira seq scan. */
    public const MINIMUM_TERM_LENGTH = 3;

    public function __construct(private readonly FuzzyMatchExpressionBuilder $expressions) {}

    /**
     * @param  Builder<Prescription>  $query
     * @return Builder<Prescription>
     */
    public function apply(Builder $query, ?string $rawTerm): Builder
    {
        $term = $this->normalizeTerm($rawTerm);

        if ($term === null) {
            return $query;
        }

        return $query->where(function (Builder $scoped) use ($term): void {
            $scoped
                ->whereIn('pet_id', fn (QueryBuilder $pets) => $this->matchingPets($pets, $term))
                ->orWhereIn('pet_id', fn (QueryBuilder $pets) => $this->petsOfMatchingTutors($pets, $term))
                ->orWhereRaw($this->medicationExpression(), ['%'.$term.'%']);
        });
    }

    private function normalizeTerm(?string $rawTerm): ?string
    {
        $term = trim((string) $rawTerm);

        return mb_strlen($term) < self::MINIMUM_TERM_LENGTH ? null : $term;
    }

    private function matchingPets(QueryBuilder $pets, string $term): void
    {
        $pets->select('id')->from('pets')->whereNull('deleted_at');

        $this->whereNameMatches($pets, 'pets.name', $term);
    }

    private function petsOfMatchingTutors(QueryBuilder $pets, string $term): void
    {
        $pets->select('pets.id')
            ->from('pets')
            ->join('users', 'users.id', '=', 'pets.user_id')
            ->whereNull('pets.deleted_at')
            ->whereNull('users.deleted_at');

        $this->whereNameMatches($pets, 'users.name', $term);
    }

    /**
     * `%` (operador do pg_trgm) tolera erro de digitação e acento; o `ILIKE '%termo%'` cobre o
     * caso "digitei o começo do nome". Os dois são servidos pelo MESMO índice GIN trigram.
     */
    private function whereNameMatches(QueryBuilder $query, string $column, string $term): void
    {
        $query->whereRaw(
            '('.$this->expressions->fuzzyMatch($column).' OR '.$this->expressions->ilikeMatch($column).')',
            [$term, '%'.$term.'%']
        );
    }

    private function medicationExpression(): string
    {
        return $this->expressions->ilikeMatch('prescriptions.medications::jsonb::text');
    }
}
