<?php

namespace App\DataTransferObjects\Search;

/**
 * Um predicado SQL pronto com os bindings dele, na ordem certa.
 *
 * Existe para que expressão e bindings andem SEMPRE juntos. Antes, quem montava o SQL
 * contava à mão quantos `?` tinha produzido e montava o array de bindings noutro lugar do
 * arquivo — o tipo de acoplamento que não dá erro quando quebra: os bindings deslizam uma
 * posição, o Postgres aceita a query, e a busca passa a comparar a agulha com o limiar.
 */
final readonly class FieldMatch
{
    /**
     * @param  list<string|float>  $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
    ) {}
}
