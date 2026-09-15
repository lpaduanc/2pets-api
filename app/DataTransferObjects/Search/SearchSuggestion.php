<?php

namespace App\DataTransferObjects\Search;

/**
 * Um "você quis dizer..." já resolvido: o conceito alternativo, como exibi-lo e quantos
 * resultados ele tem SOB OS MESMOS filtros e a mesma geolocalização da busca atual.
 *
 * `total` sair da mesma combinação de filtros não é detalhe de implementação, é o contrato:
 * uma sugestão que leva a zero resultados é pior do que sugestão nenhuma, porque transforma
 * uma busca imperfeita (que devolveu algo) numa tela vazia.
 *
 * `term` é exatamente o que o cliente reenviará como `?query=` — e é a forma canônica
 * primária do conceito, não o rótulo. Rótulo tem acento, maiúscula e barra; termo canônico é
 * o que o vocabulário reconhece sem depender de nenhuma delas.
 */
final readonly class SearchSuggestion
{
    public function __construct(
        public string $term,
        public string $label,
        public int $total,
    ) {}

    /**
     * @return array{term: string, label: string, total: int}
     */
    public function toArray(): array
    {
        return ['term' => $this->term, 'label' => $this->label, 'total' => $this->total];
    }
}
