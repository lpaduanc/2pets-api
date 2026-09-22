<?php

namespace App\Services\Search;

use App\Enums\Search\WordSimilarityMode;
use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Condições sobre os dois atributos do profissional que moram em coluna JSON:
 * `specialties` (TEXT com JSON dentro) e `species_served` (JSON).
 *
 * Devolve CONDIÇÕES (closures), não aplica nada na query externa. É o que permite a
 * `ProfessionalSearchService` juntar especialidade, espécie, tipo e nota numa ÚNICA subquery
 * sobre `professionals` — decisão de plano medida: com cada filtro em sua própria subquery,
 * a consulta "termo + tipo + nota + geo" levava **42,8 s** (o Postgres encadeia um semi-join
 * por filtro e, com a cardinalidade externa estimada em 1, escolhe `Seq Scan` no último);
 * com todos na mesma subquery, **137 ms**. Cada filtro isolado sempre esteve rápido — é a
 * combinação que quebrava.
 *
 * ── Decisão sobre a taxonomia de `professionals.specialties` ───────────────────────────
 * A coluna tem taxonomia incoerente na base atual: 45 mil linhas de seed com rótulo
 * acentuado (`["Clínica Geral","Vacinação"]`), um punhado com slug (`["cardiologia",
 * "clinica_geral"]`) e algumas em inglês (`["general","dermatology"]`). Não existe tabela
 * pivô profissional↔especialidade.
 *
 * Caminho escolhido: NORMALIZAR NA CONSULTA, não migrar os dados. Motivos:
 * 1. Uma migration que reescreve 45k linhas de texto livre não tem como ser conferida e é
 *    irreversível na prática (não dá para saber qual era o valor original de cada linha
 *    depois de mapear `"general"` para `"clinica geral"`).
 * 2. Ela também não resolveria: o cadastro continua gravando o que o front mandar, então a
 *    incoerência voltaria na semana seguinte. A correção de verdade é uma tabela pivô com
 *    FK para `specialties` — mudança de escrita, fora do escopo desta tarefa, registrada
 *    como dívida.
 * 3. A normalização na consulta é taxonomia-agnóstica por construção: `_`, `-` e `/` viram
 *    espaço e o acento cai, então `clinica_geral`, `Clínica Geral` e
 *    `Fisioterapia/Reabilitacao` chegam na mesma forma que o vocabulário guarda.
 *
 * O predicado é composto de propósito: o operador trigram é o PRÉ-FILTRO (usa o índice GIN)
 * e a comparação por palavra inteira é o CRITÉRIO (garante precisão — sem ela,
 * `?specialty=cardiologia` traria também quem tem "cardiologia pediátrica" escrito de
 * qualquer jeito, e `ILIKE '%...%'` casaria no meio de outra palavra).
 */
final class ProfessionalAttributeFilter
{
    /**
     * `translate` em vez de `regexp_replace`: é mais barato e o conjunto de separadores é
     * fixo e conhecido. O `lower()` é necessário porque `immutable_unaccent` não muda caixa
     * (diferente do trigrama, que compara sempre em minúsculas).
     */
    private const NORMALIZED_SPECIALTIES = "translate(lower(%s), '_-/', '   ')";

    private const SPECIALTIES_COLUMN = 'professionals.specialties';

    /**
     * Fase 7 do fluxo de agendamento: especialidades da EQUIPE bookável/ativa, espelhadas
     * pelo dono via `App\Services\Organization\TeamSpecialtyAggregator` — permite uma
     * clínica aparecer buscando a especialidade de um veterinário da equipe (ex.:
     * cardiologia), mesmo que o dono não seja ele mesmo cardiologista. `NULL` para quem
     * não possui organização — o predicado abaixo simplesmente nunca casa nesse caso,
     * então esta coluna nova não muda em nada o resultado para profissional avulso.
     */
    private const TEAM_SPECIALTIES_COLUMN = 'professionals.team_specialties';

    public function __construct(
        private readonly SearchVocabulary $vocabulary,
        private readonly FuzzyMatchExpressionBuilder $expressions,
    ) {}

    /**
     * OR entre as formas armazenadas de TODAS as especialidades pedidas, num ÚNICO grupo
     * aninhado dentro da subquery que o chamador já abriu. Multi-seleção NÃO pode virar uma
     * subquery (nem um `whereIn`) por termo: seria exatamente a composição que mediu 42,8 s
     * — ver o docblock da classe e o de `ProfessionalSearchService::applyProfessionalFilters()`.
     *
     * @param  list<string>  $specialties
     * @return Closure(QueryBuilder): void
     */
    public function specialtyCondition(array $specialties): Closure
    {
        $forms = $this->storedFormsForAll($specialties);

        return fn (QueryBuilder $sub) => $sub->where(fn (QueryBuilder $group) => $this->matchAnyForm($group, $forms));
    }

    /**
     * Formas deduplicadas: dois termos do mesmo conceito ("cardiologia" e "cardiologista")
     * compartilham as formas canônicas, e repeti-las só duplicaria predicados no OR.
     *
     * @param  list<string>  $specialties
     * @return list<string>
     */
    private function storedFormsForAll(array $specialties): array
    {
        $forms = [];

        foreach ($specialties as $specialty) {
            $forms = [...$forms, ...$this->vocabulary->storedFormsFor($specialty)];
        }

        return array_values(array_unique($forms));
    }

    /**
     * Semântica ESTRITA: só entra quem declarou a espécie. Quem deixou `species_served`
     * vazio não é "atende tudo", é "não informou" — e o produto pediu explicitamente para
     * nunca trazer quem não corresponde ao que foi buscado. Consequência conhecida: na base
     * de seed atual quase ninguém declarou espécie, então este filtro devolve pouco; isso é
     * lacuna de DADO, não do filtro (registrado como dívida).
     *
     * Multi-seleção cabe num operador só: `jsonb_exists_any` é "a coluna contém QUALQUER uma
     * destas chaves", que é exatamente o OR da dimensão — sem um predicado por espécie e sem
     * grupo aninhado.
     *
     * `jsonb_exists_any(...)` e não o operador `?|` do jsonb: `?` colidiria com o placeholder
     * de binding do PDO. Um placeholder por espécie dentro do `ARRAY[...]` mantém tudo
     * parametrizado — nenhuma lista é interpolada na string SQL.
     *
     * @param  list<string>  $species
     * @return Closure(QueryBuilder): void
     */
    public function speciesCondition(array $species): Closure
    {
        $placeholders = implode(', ', array_fill(0, count($species), '?'));

        return fn (QueryBuilder $sub) => $sub->whereRaw(
            "jsonb_exists_any(professionals.species_served::jsonb, ARRAY[{$placeholders}]::text[])",
            $species,
        );
    }

    /**
     * Cada forma casa contra a especialidade PRÓPRIA ou a da EQUIPE — mesmo grupo `OR` que
     * já existia, só com mais um par de predicados por forma. Continua sendo a MESMA
     * subquery não-correlacionada sobre `professionals`; nenhuma tabela nova entra aqui
     * (ver `ProfessionalSearchService::applyProfessionalFilters()` e o relato de
     * performance da Fase 7 — a alternativa com `EXISTS` contra `organization_members` foi
     * medida e descartada).
     *
     * @param  list<string>  $forms
     */
    private function matchAnyForm(QueryBuilder $group, array $forms): void
    {
        foreach ($forms as $form) {
            $group->orWhereRaw($this->formPredicate(self::SPECIALTIES_COLUMN), [$form, $form]);
            $group->orWhereRaw($this->formPredicate(self::TEAM_SPECIALTIES_COLUMN), [$form, $form]);
        }
    }

    private function formPredicate(string $column): string
    {
        $normalized = sprintf(
            self::NORMALIZED_SPECIALTIES,
            $this->expressions->immutableUnaccent($column),
        );

        // `\m`/`\M` = início/fim de palavra no regex do Postgres. É o que impede
        // "cardiologia" de casar dentro de "cardiologiaX" e o que dá precisão ao filtro.
        return '('.$this->expressions->wordSimilarityMatch($column, WordSimilarityMode::WORD)
            ." AND {$normalized} ~ ('\\m' || ? || '\\M'))";
    }
}
