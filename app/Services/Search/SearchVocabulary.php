<?php

namespace App\Services\Search;

use App\DataTransferObjects\Search\SearchConcept;
use App\Support\Search\SearchConceptDefinitions;

/**
 * Índice de consulta do vocabulário: alias normalizado → conceito.
 *
 * Separado de `SearchConceptDefinitions` (que é só dado) porque aqui mora a decisão difícil:
 * quando aceitar que um termo com erro de digitação "é" um conceito. O critério é
 * deliberadamente conservador — o dono do produto pediu precisão acima de recall, e uma
 * expansão errada não deixa o resultado pior de um jeito visível, ela só traz gente que não
 * tem nada a ver com a busca.
 */
final class SearchVocabulary
{
    /**
     * Um typo aceitável muda poucos caracteres: "cargiolista" → "cardiologista" tem
     * `similar_text` 83,3% e distância 3. Os pares que MEDI e que precisam ser rejeitados
     * ficam logo abaixo desse corte — "oncologia"/"odontologia" (80,0%) e
     * "cardiologia"/"dermatologia" (60,9%). Daí 82%, e não 80%.
     *
     * O risco de par ambíguo real ("neurologia"/"nefrologia", 90%) não existe na prática
     * porque o casamento EXATO roda primeiro: uma palavra que está no vocabulário nunca
     * chega ao passo fuzzy.
     */
    private const MIN_TYPO_SIMILARITY_PERCENT = 82.0;

    /** Acima disso não é mais erro de digitação, é outra palavra. */
    private const MAX_TYPO_EDIT_DISTANCE = 3;

    /**
     * Termo curto tem pouca informação: "vet", "dor" e "cao" ficariam a 1-2 edições de
     * meia dúzia de conceitos. Abaixo deste tamanho só vale casamento exato.
     */
    private const MIN_TYPO_TERM_LENGTH = 5;

    /**
     * Prefixo precisa de massa para significar alguma coisa. Com 3 letras, "car" é prefixo de
     * "cardiologia" e de "cardiaco" (mesmo conceito, tudo bem) mas também de nada mais no
     * vocabulário — o problema é outro: 3 letras é o piso do termo de busca inteiro, e
     * transformar todo termo mínimo em conceito tiraria do usuário a busca por nome próprio
     * curto. 4 é o menor tamanho em que o prefixo carrega intenção ("fisio", "derma",
     * "odonto", "orto" têm 4+).
     */
    private const MIN_PREFIX_LENGTH = 4;

    /** @var array<string, SearchConcept>|null */
    private ?array $conceptsByAlias = null;

    private ?int $longestAliasWordCount = null;

    public function __construct(private readonly SearchTextNormalizer $normalizer) {}

    /**
     * Casamento exato pela chave canônica — o caminho de 99% das buscas.
     */
    public function conceptFor(string $term): ?SearchConcept
    {
        return $this->aliasIndex()[$this->normalizer->canonicalKey($term)] ?? null;
    }

    /**
     * Termo que é PREFIXO de um alias conhecido: "cardio" → cardiologia, "derma" →
     * dermatologia, "oftalmo" → oftalmologia, "orto" → ortopedia.
     *
     * É como as pessoas digitam de verdade, e a ausência disso era o bug mais importante dos
     * reportados pelo dono do produto: "cardio" não encontrava Cardiologia. O passo de typo
     * não resolvia — `levenshtein('cardio', 'cardiologia')` = 5, acima do teto de 3, e
     * `similar_text` dá 70,6%, abaixo do corte de 82%. Um prefixo não é um erro de digitação:
     * é uma palavra incompleta, e precisa do próprio critério.
     *
     * Roda DEPOIS do casamento exato e ANTES do de typo — prefixo é evidência mais forte que
     * semelhança difusa, e resolver antes evita que "cardio" caia em algum alias parecido por
     * acaso.
     *
     * ── A guarda de ambiguidade ───────────────────────────────────────────────────────
     * Prefixo que serve a MAIS DE UM conceito não resolve para nenhum. "medi" é prefixo de
     * "medicina felina" e de "medicina de animais silvestres exoticos": escolher um seria
     * decidir pelo usuário com base na ordem do array. Nesse caso a busca cai no termo cru,
     * que continua encontrando as duas por similaridade — comportamento correto e honesto.
     */
    public function prefixConceptFor(string $term): ?SearchConcept
    {
        $candidate = $this->normalizer->canonicalKey($term);

        if (mb_strlen($candidate) < self::MIN_PREFIX_LENGTH) {
            return null;
        }

        return $this->unambiguousPrefixMatch($candidate);
    }

    /**
     * Os conceitos DISTINTOS que o termo alcança por prefixo de palavra — a matéria-prima do
     * "você quis dizer...".
     *
     * Diferente de `prefixConceptFor()` em duas coisas, e as duas são deliberadas:
     *
     * 1. **Devolve todos, não desiste na ambiguidade.** `prefixConceptFor()` existe para
     *    RESOLVER o termo e, por isso, prefere não decidir a decidir errado. Aqui a
     *    ambiguidade é justamente o dado procurado.
     * 2. **Casa prefixo de QUALQUER palavra do alias, não só do começo.** Sem isso, "car"
     *    não alcançaria "day care" — e "day care" é exatamente o motivo de `?query=car`
     *    devolver creche e hotel no topo, com os cardiologistas ranqueados abaixo. Um
     *    "você quis dizer" que não enxerga a causa da confusão não serve para nada.
     *
     * Não altera a busca: nenhum caminho de filtro ou ranking chama este método.
     *
     * @return list<SearchConcept>
     */
    public function conceptsMatchingPrefix(string $term): array
    {
        $candidate = $this->normalizer->canonicalKey($term);

        if ($candidate === '') {
            return [];
        }

        return $this->distinctConceptsForPrefix($candidate);
    }

    /**
     * Última tentativa, só para termo que não casou exato: o alias mais parecido, desde que
     * parecido o bastante pelos DOIS critérios (proporção e número de edições).
     */
    public function closestConceptFor(string $term): ?SearchConcept
    {
        $candidate = $this->normalizer->canonicalKey($term);

        if (mb_strlen($candidate) < self::MIN_TYPO_TERM_LENGTH) {
            return null;
        }

        return $this->bestTypoMatch($candidate);
    }

    /**
     * Formas que o filtro exato `?specialty=` aceita como "gravadas" para um valor vindo do
     * cliente. Quando o valor cai num conceito conhecido, devolve as formas canônicas dele
     * (é o que faz `?specialty=Cardiologia`, `?specialty=cardiologia` e
     * `?specialty=cardiologista` casarem a mesma linha). Quando não cai, devolve o próprio
     * valor normalizado — filtro por especialidade que ainda não está no vocabulário
     * continua funcionando de forma literal em vez de silenciosamente não filtrar nada.
     *
     * @return list<string>
     */
    public function storedFormsFor(string $value): array
    {
        $concept = $this->conceptFor($value) ?? $this->closestConceptFor($value);

        return $concept?->storedForms() ?? array_values(array_filter([$this->normalizer->normalize($value)]));
    }

    /**
     * Quantas palavras tem o maior alias do vocabulário — define a janela do interpretador
     * ao tentar casar "banho e tosa" antes de tentar "banho" sozinho. Derivado do dado, e
     * não uma constante: alias novo de 4 palavras passa a funcionar sem ninguém lembrar de
     * ajustar um número em outro arquivo.
     */
    public function longestAliasWordCount(): int
    {
        return $this->longestAliasWordCount ??= max(array_map(
            static fn (string $alias): int => substr_count($alias, ' ') + 1,
            array_keys($this->aliasIndex()),
        ));
    }

    /**
     * @return list<SearchConcept>
     */
    private function distinctConceptsForPrefix(string $candidate): array
    {
        $concepts = [];

        foreach ($this->aliasIndex() as $alias => $concept) {
            if ($this->aliasHasWordStartingWith($alias, $candidate)) {
                $concepts[$concept->key] = $concept;
            }
        }

        return array_values($concepts);
    }

    private function aliasHasWordStartingWith(string $alias, string $candidate): bool
    {
        foreach (explode(' ', $alias) as $word) {
            if (str_starts_with($word, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O conceito de todos os aliases que começam com o candidato — ou `null` se houver mais
     * de um conceito distinto entre eles.
     */
    private function unambiguousPrefixMatch(string $candidate): ?SearchConcept
    {
        $matched = null;

        foreach ($this->aliasIndex() as $alias => $concept) {
            if (! str_starts_with($alias, $candidate)) {
                continue;
            }

            if ($matched !== null && $matched->key !== $concept->key) {
                return null;
            }

            $matched = $concept;
        }

        return $matched;
    }

    private function bestTypoMatch(string $candidate): ?SearchConcept
    {
        $bestConcept = null;
        $bestScore = self::MIN_TYPO_SIMILARITY_PERCENT;

        foreach ($this->aliasIndex() as $alias => $concept) {
            $score = $this->typoScore($candidate, $alias);

            if ($score !== null && $score > $bestScore) {
                $bestScore = $score;
                $bestConcept = $concept;
            }
        }

        return $bestConcept;
    }

    private function typoScore(string $candidate, string $alias): ?float
    {
        if (levenshtein($candidate, $alias) > self::MAX_TYPO_EDIT_DISTANCE) {
            return null;
        }

        similar_text($candidate, $alias, $percent);

        return $percent;
    }

    /**
     * @return array<string, SearchConcept>
     */
    private function aliasIndex(): array
    {
        return $this->conceptsByAlias ??= $this->buildAliasIndex();
    }

    /**
     * @return array<string, SearchConcept>
     */
    private function buildAliasIndex(): array
    {
        $index = [];

        foreach (SearchConceptDefinitions::all() as $definition) {
            $concept = $this->toConcept($definition);

            foreach ($definition['aliases'] as $alias) {
                $index[$this->normalizer->canonicalKey($alias)] = $concept;
            }
        }

        unset($index['']);

        return $index;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function toConcept(array $definition): SearchConcept
    {
        return new SearchConcept(
            key: $definition['key'],
            terms: array_map(fn (string $term): string => $this->normalizer->normalize($term), $definition['terms']),
            professionalType: $definition['professional_type'],
            serviceCategory: $definition['service_category'],
        );
    }
}
