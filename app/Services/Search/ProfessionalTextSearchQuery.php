<?php

namespace App\Services\Search;

use App\DataTransferObjects\Search\SearchConcept;
use App\DataTransferObjects\Search\SearchUnit;
use App\Enums\Search\SearchField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Busca textual da lista pública de profissionais — o FILTRO (quem entra). O ranking (em que
 * ordem) mora em `ProfessionalRelevanceRanking`.
 *
 * Forma do WHERE: E entre as unidades do termo, OU entre as evidências dentro de cada
 * unidade. "clinica veterinaria 24h" exige que CADA unidade case em ALGUMA evidência — é o
 * que impede a frase inteira de virar um casamento frouxo.
 *
 * ── A REGRA DE QUALIFICAÇÃO (2026-09-14) ───────────────────────────────────────────────
 * Cada unidade cai num de dois regimes, e a diferença entre eles é a correção central desta
 * versão:
 *
 * **Termo conceitual com categoria de serviço** ("banho e tosa", "vacinação", "cardiologia",
 * "raio-x"): só qualifica por EVIDÊNCIA — nome de serviço ativo, especialidade declarada,
 * categoria de serviço, oferta declarada no cadastro ou equipamento. Nome e
 * `professional_type` NÃO qualificam. Era exatamente a queixa do dono do produto: um
 * estabelecimento com `professional_type = grooming` cujos serviços eram "clínica geral" e
 * "vacinação" aparecia em "banho e tosa". Agora não aparece.
 *
 * **Termo desconhecido ou conceito que é só tipo de negócio** ("Freitas", "Deluxe",
 * "clínica", "petshop"): busca aberta em nome, razão social, especialidade, serviço e
 * descrição. Buscar por nome é uso legítimo e não pode quebrar; e para "clínica" não existe
 * evidência mais dura que o tipo (ver `SearchConcept::requiresCompetenceEvidence()`).
 *
 * Termo que é as duas coisas resolve por composição: cada unidade da frase escolhe o próprio
 * regime e todas são E-ligadas, então "clinica freitas" exige a evidência da primeira E o
 * nome da segunda, com a evidência dura ranqueando acima.
 *
 * ── Por que `whereHas`/EXISTS e nunca LEFT JOIN ────────────────────────────────────────
 * Lição medida com EXPLAIN (Fase 5; `agent-memory/.../busca-fuzzy-fase5.md`): o Postgres só
 * combina (`BitmapOr`) vários índices GIN quando TODAS as condições do OR pertencem a UMA
 * tabela sendo varrida. Um OR que mistura `users.name` com coluna trazida por LEFT JOIN
 * obriga o planner a materializar o join antes de filtrar, e nenhum índice trigram é usado.
 * Pior: combinado com `ST_DWithin`, o LEFT JOIN fez o planner estimar 1 linha e escolher
 * `Seq Scan` dentro de um Nested Loop.
 *
 * ── Por que as subqueries NÃO são correlacionadas ──────────────────────────────────────
 * `IN (SELECT user_id FROM ...)` e não `EXISTS (... WHERE user_id = users.id)`. Medido: com
 * `ST_DWithin` presente, o planner estima `rows=1` do lado de fora e mantém o subplan
 * correlacionado — 26.190 loops, 3,2 s para devolver 1 linha. Sem correlação, esse plano
 * deixa de ser possível: o conjunto sai do índice GIN uma vez e vira hash.
 *
 * ── Por que o ramo estrutural fica separado do textual ─────────────────────────────────
 * Basta um `category = 'grooming'` (que nenhum índice trigram serve) no meio do OR para o
 * Postgres desistir do `BitmapOr` e varrer a tabela aplicando o OR inteiro como filtro.
 * Medido em `services` (180k linhas), "banho e tosa": misturado 2.947 ms; separado 187 ms.
 */
final class ProfessionalTextSearchQuery
{
    public function __construct(
        private readonly SearchQueryInterpreter $interpreter,
        private readonly SearchFieldMatcher $matcher,
        private readonly ProfessionalRelevanceRanking $relevanceRanking,
        private readonly StructuralEvidenceQuery $structuralEvidence,
    ) {}

    public function apply(Builder $query, string $rawQuery): void
    {
        $interpreted = $this->interpreter->interpret($rawQuery);

        if ($interpreted->isEmpty()) {
            return;
        }

        foreach ($interpreted->units as $unit) {
            $query->where(fn (Builder $group) => $this->matchUnit($group, $unit));
        }

        $this->relevanceRanking->applyTo($query, $interpreted);
    }

    private function matchUnit(Builder $group, SearchUnit $unit): void
    {
        $concept = $unit->concept;

        if ($concept !== null && $concept->requiresCompetenceEvidence()) {
            $this->matchByCompetence($group, $unit, $concept);

            return;
        }

        $this->matchOpenly($group, $unit);
    }

    /**
     * Regime restrito: só as camadas 1 e 2 da hierarquia (`RelevanceLayer`).
     *
     * As quatro evidências vão em subqueries SEPARADAS de propósito — duas textuais
     * (servidas por GIN trigram) e duas estruturais (servidas por btree/GIN jsonb).
     * Misturá-las num OR só destruiria o `BitmapOr`, pelo motivo medido no docblock da
     * classe.
     */
    private function matchByCompetence(Builder $group, SearchUnit $unit, SearchConcept $concept): void
    {
        $group->orWhereIn('users.id', fn (QueryBuilder $sub) => $this->specialtyOwnerIds($sub, $unit));
        $group->orWhereIn('users.id', fn (QueryBuilder $sub) => $this->serviceNameOwnerIds($sub, $unit));
        $this->structuralEvidence->orMatchServiceCategory($group, $concept);
        $this->structuralEvidence->orMatchDeclaredOffering($group, $concept);
    }

    /**
     * Regime aberto: todo campo textual coberto, incluindo nome e descrição. É o caminho da
     * busca por nome próprio, que precisa continuar funcionando — e do conceito que é só
     * tipo de negócio, onde o tipo também qualifica.
     */
    private function matchOpenly(Builder $group, SearchUnit $unit): void
    {
        foreach ($unit->needles as $needle) {
            $this->orMatch($group, SearchField::USER_NAME, $needle);
        }

        $group->orWhereIn('users.id', fn (QueryBuilder $sub) => $this->professionalIds($sub, $unit));
        $group->orWhereIn('users.id', fn (QueryBuilder $sub) => $this->serviceOwnerIds($sub, $unit));

        if ($unit->concept !== null) {
            $this->structuralEvidence->orMatchProfessionalType($group, $unit->concept);
        }
    }

    /** Camada 1 sobre `professionals`: só a especialidade declarada. */
    private function specialtyOwnerIds(QueryBuilder $sub, SearchUnit $unit): void
    {
        $this->fromProfessionals($sub, $unit, [SearchField::SPECIALTIES]);
    }

    /** Regime aberto sobre `professionals`: razão social, especialidade e descrição. */
    private function professionalIds(QueryBuilder $sub, SearchUnit $unit): void
    {
        $this->fromProfessionals($sub, $unit, [
            SearchField::BUSINESS_NAME,
            SearchField::SPECIALTIES,
            SearchField::PROFESSIONAL_DESCRIPTION,
        ]);
    }

    /**
     * @param  list<SearchField>  $fields
     */
    private function fromProfessionals(QueryBuilder $sub, SearchUnit $unit, array $fields): void
    {
        $sub->select('professionals.user_id')
            ->from('professionals')
            ->whereNull('professionals.deleted_at')
            ->where(fn (QueryBuilder $group) => $this->matchAnyField($group, $unit, $fields));
    }

    /** Camada 1 sobre `services`: só o nome do serviço. */
    private function serviceNameOwnerIds(QueryBuilder $sub, SearchUnit $unit): void
    {
        $this->fromServices($sub, $unit, [SearchField::SERVICE_NAME]);
    }

    /** Regime aberto sobre `services`: nome e descrição. */
    private function serviceOwnerIds(QueryBuilder $sub, SearchUnit $unit): void
    {
        $this->fromServices($sub, $unit, [SearchField::SERVICE_NAME, SearchField::SERVICE_DESCRIPTION]);
    }

    /**
     * `services.professional_id` referencia `users.id` (não `professionals.id`), então a
     * lista de ids sai direto daqui.
     *
     * @param  list<SearchField>  $fields
     */
    private function fromServices(QueryBuilder $sub, SearchUnit $unit, array $fields): void
    {
        $sub->select('services.professional_id')
            ->from('services')
            ->whereRaw('services.active = true')
            ->whereNull('services.deleted_at')
            ->where(fn (QueryBuilder $group) => $this->matchAnyField($group, $unit, $fields));
    }

    /**
     * @param  list<SearchField>  $fields
     */
    private function matchAnyField(QueryBuilder $group, SearchUnit $unit, array $fields): void
    {
        foreach ($unit->needles as $needle) {
            foreach ($fields as $field) {
                $this->orMatch($group, $field, $needle);
            }
        }
    }

    /**
     * A ordem do laço acima é agulha-por-fora / campo-por-dentro, e isso é latência medida:
     * o Postgres avalia os ramos do OR na ordem escrita com curto-circuito, e as agulhas
     * chegam da mais curta para a mais longa (`SearchUnit::shortestFirst()`) — a mais curta é
     * a mais permissiva e faz a maioria das linhas sair no primeiro ramo. Medido em
     * `professionals` (45k linhas), "clinica veterinaria": 1.077 ms com a agulha longa
     * primeiro, 419 ms com a curta.
     */
    private function orMatch(Builder|QueryBuilder $group, SearchField $field, string $needle): void
    {
        $match = $this->matcher->filter($field, $needle);

        $group->orWhereRaw($match->sql, $match->bindings);
    }
}
