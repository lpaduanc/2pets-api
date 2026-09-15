<?php

namespace App\DataTransferObjects\Search;

/**
 * Uma exigência da busca: o pedaço do termo que o profissional PRECISA casar em algum campo
 * para entrar no resultado.
 *
 * É a peça que sustenta "precisão acima de recall" para termo multi-palavra: "clinica
 * veterinaria 24h" vira três unidades E-ligadas, não uma string única solta num ILIKE.
 * Cada unidade sozinha é permissiva (casa contra qualquer campo); o conjunto é rígido.
 */
final readonly class SearchUnit
{
    /**
     * @param  list<string>  $needles  formas normalizadas que satisfazem esta unidade
     */
    private function __construct(
        public array $needles,
        public ?SearchConcept $concept,
    ) {}

    public static function fromTerm(string $term): self
    {
        return new self([$term], null);
    }

    /**
     * O termo digitado continua na lista mesmo quando o conceito foi reconhecido: o
     * vocabulário AMPLIA o que casa, nunca substitui o que o usuário escreveu. Quem digitou
     * "fisio" e existe uma clínica chamada "Fisio Pet" precisa achá-la, não só quem tem a
     * especialidade cadastrada.
     */
    public static function fromConcept(string $term, SearchConcept $concept): self
    {
        return new self(self::shortestFirst([$term, ...$concept->sqlTerms()]), $concept);
    }

    /**
     * A ordem das agulhas é decisão de LATÊNCIA, não estética.
     *
     * O SQL vira um OR e o Postgres avalia os ramos na ordem escrita, com curto-circuito. A
     * agulha mais curta é a mais permissiva (`word_similarity` procura a agulha DENTRO do
     * campo, então quanto menor a agulha, mais campos a contêm), logo é a que mais sai no
     * primeiro ramo. Medido em `professionals` (45k linhas), termo "clinica veterinaria":
     * agulha longa primeiro, 1.077 ms; agulha curta primeiro, **419 ms**. Mesmo predicado,
     * mesmo resultado, 2,6× — só mudou quantas vezes `word_similarity` roda por linha antes
     * de alguém dar `true`.
     *
     * @param  list<string>  $needles
     * @return list<string>
     */
    private static function shortestFirst(array $needles): array
    {
        $unique = array_values(array_unique($needles));
        usort($unique, static fn (string $first, string $second): int => mb_strlen($first) <=> mb_strlen($second));

        return $unique;
    }
}
