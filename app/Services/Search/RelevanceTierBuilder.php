<?php

namespace App\Services\Search;

use App\DataTransferObjects\Search\FieldMatch;
use App\DataTransferObjects\Search\InterpretedSearchQuery;
use App\DataTransferObjects\Search\SearchConcept;
use App\Enums\Search\RelevanceLayer;
use App\Enums\Search\SearchField;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Os "tiers planos" do ranking: `CASE WHEN users.id IN (<conjunto>) THEN <peso> ELSE 0 END`.
 *
 * Por que tier e não pontuação contínua: a versão contínua exigiria varrer as linhas de
 * `services`/`professionals` de CADA candidato. Medido — 619 ms dos 1.097 ms do ranking num
 * termo que casa 27 mil profissionais. O `IN (subquery não correlacionada)` vira subplan
 * HASHEADO: construído uma vez (~15 ms) e consultado por hash. 1.097 → 514 ms.
 *
 * Cada tier devolve `null` quando não se aplica ao termo buscado, e quem monta a expressão
 * simplesmente não o inclui — em vez de somar um `0` inútil ao `GREATEST` e, pior, um
 * binding sem par.
 */
final class RelevanceTierBuilder
{
    public function __construct(private readonly SearchFieldMatcher $matcher) {}

    /** Camada 1 — casou o nome de algum serviço ATIVO. */
    public function serviceName(InterpretedSearchQuery $interpreted): FieldMatch
    {
        $subquery = DB::table('services')
            ->select('services.professional_id')
            ->whereRaw('services.active = true')
            ->whereNull('services.deleted_at')
            ->where(fn (QueryBuilder $group) => $this->matchAnyNeedleIn($group, $interpreted, SearchField::SERVICE_NAME));

        return $this->tier($subquery, (float) SearchField::SERVICE_NAME->relevanceWeight());
    }

    /**
     * Camada 1 — casou a especialidade declarada.
     *
     * Tier, e não pontuação contínua, e o motivo é de CORREÇÃO da hierarquia, não de
     * latência: com a especialidade contínua (0,95 × similaridade) e a evidência estrutural
     * plana (0,75), um casamento parcial de especialidade perdia para a camada de baixo.
     * Medido com "fisio": quem tinha a especialidade "Fisioterapia/Reabilitacao" pontuava
     * 0,95 × 0,5 = 0,475 e caía ABAIXO de quem só tinha serviço de reabilitação (0,75) — a
     * hierarquia invertia exatamente onde ela mais importa.
     *
     * Regra que saiu daí: **camada de evidência é tier; só a camada de NOME é contínua.** O
     * nome é a camada mais baixa que pontua (0,25), então um valor contínuo ali nunca
     * consegue cruzar para a camada de cima, e a gradação "nome exato × nome parecido" —
     * que foi requisito explícito — continua existindo onde faz sentido.
     */
    public function specialty(InterpretedSearchQuery $interpreted): FieldMatch
    {
        $subquery = DB::table('professionals')
            ->select('professionals.user_id')
            ->whereNull('professionals.deleted_at')
            ->where(fn (QueryBuilder $group) => $this->matchAnyNeedleIn($group, $interpreted, SearchField::SPECIALTIES));

        return $this->tier($subquery, (float) SearchField::SPECIALTIES->relevanceWeight());
    }

    /**
     * Camada 2 — tem serviço na categoria do conceito, declarou a oferta no cadastro ou tem o
     * equipamento. Um tier só para as três, porque as três são a mesma força de evidência.
     */
    public function structuralEvidence(InterpretedSearchQuery $interpreted): ?FieldMatch
    {
        $categories = $this->conceptCategoryValues($interpreted);

        if ($categories === []) {
            return null;
        }

        $subquery = DB::table('services')
            ->select('services.professional_id')
            ->whereRaw('services.active = true')
            ->whereNull('services.deleted_at')
            ->whereIn('services.category', $categories);

        return $this->tier($subquery, (float) RelevanceLayer::STRUCTURAL->weight());
    }

    /**
     * Camada 3 — `professional_type`. Este tier é a razão de o tipo continuar servindo para
     * ALGUMA coisa depois de perder o direito de qualificar sozinho: entre dois
     * estabelecimentos que ambos oferecem banho e tosa, o que se cadastrou COMO banho e tosa
     * sobe. Ranqueia, nunca qualifica.
     */
    public function businessType(InterpretedSearchQuery $interpreted): ?FieldMatch
    {
        $types = $this->conceptTypeValues($interpreted);

        if ($types === []) {
            return null;
        }

        $subquery = DB::table('professionals')
            ->select('professionals.user_id')
            ->whereNull('professionals.deleted_at')
            ->whereIn('professionals.professional_type', $types);

        return $this->tier($subquery, (float) RelevanceLayer::BUSINESS_TYPE->weight());
    }

    private function tier(QueryBuilder $subquery, float $weight): FieldMatch
    {
        return new FieldMatch(
            sprintf('CASE WHEN users.id IN (%s) THEN %.2F ELSE 0 END', $subquery->toSql(), $weight),
            $subquery->getBindings(),
        );
    }

    /**
     * Usa as MESMAS agulhas e o MESMO regime de comparação do filtro (via
     * `SearchFieldMatcher`), para a pontuação nunca premiar um casamento que o filtro não
     * teria aceitado — nem deixar de premiar um que ele aceitou.
     */
    private function matchAnyNeedleIn(QueryBuilder $group, InterpretedSearchQuery $interpreted, SearchField $field): void
    {
        foreach ($interpreted->needles() as $needle) {
            $match = $this->matcher->indexed($field, $needle);
            $group->orWhereRaw($match->sql, $match->bindings);
        }
    }

    /**
     * @return list<string>
     */
    private function conceptCategoryValues(InterpretedSearchQuery $interpreted): array
    {
        return $this->conceptValues(
            $interpreted,
            static fn (SearchConcept $concept): ?string => $concept->serviceCategory?->value,
        );
    }

    /**
     * @return list<string>
     */
    private function conceptTypeValues(InterpretedSearchQuery $interpreted): array
    {
        return $this->conceptValues(
            $interpreted,
            static fn (SearchConcept $concept): ?string => $concept->professionalType?->value,
        );
    }

    /**
     * @param  callable(SearchConcept): ?string  $extract
     * @return list<string>
     */
    private function conceptValues(InterpretedSearchQuery $interpreted, callable $extract): array
    {
        $values = [];

        foreach ($interpreted->units as $unit) {
            $value = $unit->concept === null ? null : $extract($unit->concept);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }
}
