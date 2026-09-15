<?php

namespace App\DataTransferObjects\Search;

/**
 * O termo de busca já interpretado: a frase normalizada (usada só para RANQUEAR) e as
 * unidades obrigatórias (usadas para FILTRAR).
 *
 * Coleção de primeira classe — ninguém fora daqui manipula o array de unidades, e o teto de
 * unidades vive junto com a coleção que ele protege.
 */
final readonly class InterpretedSearchQuery
{
    /**
     * Teto de exigências. Sete palavras já são uma frase, não uma busca; sem teto, o WHERE
     * cresce linearmente com o que o usuário resolver colar na caixa de texto. As unidades
     * excedentes são descartadas (nunca relaxadas para OR) para que o resultado continue
     * sendo um subconjunto do que o usuário pediu.
     */
    private const MAX_UNITS = 6;

    /**
     * @param  list<SearchUnit>  $units
     */
    private function __construct(
        public string $phrase,
        public array $units,
    ) {}

    /**
     * @param  list<SearchUnit>  $units
     */
    public static function make(string $phrase, array $units): self
    {
        return new self($phrase, array_slice($units, 0, self::MAX_UNITS));
    }

    public function isEmpty(): bool
    {
        return $this->units === [];
    }

    /**
     * Todas as agulhas, sem repetição e das mais curtas para as mais longas — a mesma ordem
     * de curto-circuito usada dentro de cada unidade (ver `SearchUnit::shortestFirst()`).
     *
     * Serve ao RANKING, que pergunta "casou por nome de serviço?" sem se importar com qual
     * unidade casou onde. O FILTRO nunca usa esta lista: lá cada unidade é uma exigência
     * separada, e achatá-las aqui transformaria o E em OU.
     *
     * @return list<string>
     */
    public function needles(): array
    {
        $needles = [];

        foreach ($this->units as $unit) {
            $needles = [...$needles, ...$unit->needles];
        }

        $unique = array_values(array_unique($needles));
        usort($unique, static fn (string $first, string $second): int => mb_strlen($first) <=> mb_strlen($second));

        return $unique;
    }
}
