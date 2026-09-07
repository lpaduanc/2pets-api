<?php

namespace App\Services\PetAccess;

use App\Services\Search\FuzzyMatchExpressionBuilder;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtro textual da carteira de pacientes do veterinário (`PetVetAccess`).
 *
 * Substitui o filtro que o app fazia em JavaScript sobre a página já carregada — que, além de
 * não escalar, simplesmente não encontrava paciente fora das primeiras 20 linhas.
 *
 * Duas decisões de indexabilidade, ambas herdadas da Fase 5 (ver
 * `.claude/agent-memory/backend-specialist/busca-fuzzy-fase5.md`):
 *
 *   1. As subconsultas são NÃO correlacionadas (`pet_id IN (SELECT …)`), nunca `whereHas`.
 *      Um EXISTS correlacionado pela PK obriga o Postgres a resolver linha a linha pelo índice
 *      primário, e o índice GIN trigram jamais é usado. Sem correlação, o planner pode
 *      materializar o resultado (`hashed SubPlan`) e casar o GIN.
 *   2. Cada lado do OR fica sobre UMA tabela só. `BitmapOr` entre índices de tabelas
 *      diferentes não existe no Postgres.
 */
final class PatientSearchFilter
{
    /**
     * Abaixo de 3 caracteres o pg_trgm não extrai um trigrama completo e a busca degrada para
     * varredura sequencial mesmo com o índice GIN — o mesmo piso usado por `SearchFiltersDTO`.
     */
    public const MINIMUM_TERM_LENGTH = 3;

    public function __construct(private readonly FuzzyMatchExpressionBuilder $expressions) {}

    public function apply(Builder $query, ?string $rawTerm): Builder
    {
        $term = $this->normalizeTerm($rawTerm);

        if ($term === null) {
            return $query;
        }

        return $query->where(function (Builder $scoped) use ($term): void {
            $scoped
                ->whereIn('pet_id', fn (QueryBuilder $pets) => $this->matchingPets($pets, $term))
                ->orWhereIn('granted_by', fn (QueryBuilder $tutors) => $this->matchingTutors($tutors, $term));
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

    private function matchingTutors(QueryBuilder $tutors, string $term): void
    {
        $tutors->select('id')->from('users')->whereNull('deleted_at');

        $this->whereNameMatches($tutors, 'users.name', $term);
    }

    /**
     * `%` (operador do pg_trgm) tolera erro de digitação e acento; o `ILIKE '%termo%'` cobre o
     * caso "digitei o começo do nome", que o `%` descarta quando o termo é bem menor que o
     * valor da coluna. Os dois são servidos pelo MESMO índice GIN trigram.
     */
    private function whereNameMatches(QueryBuilder $query, string $column, string $term): void
    {
        $query->whereRaw(
            '('.$this->expressions->fuzzyMatch($column).' OR '.$this->expressions->ilikeMatch($column).')',
            [$term, '%'.$term.'%']
        );
    }
}
