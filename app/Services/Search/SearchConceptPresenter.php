<?php

namespace App\Services\Search;

use App\DataTransferObjects\Search\SearchConcept;
use App\Support\Catalog\VeterinarySpecialtyCatalog;

/**
 * A face humana do vocabulário de busca: dado um conceito, que termo o cliente deve reenviar
 * e que rótulo deve mostrar. Serve o "você quis dizer..." (`SearchSuggestionFinder`) e o
 * catálogo de filtros de `GET /public/categories`.
 *
 * Existe para que NENHUMA lista de especialidade seja redigitada num controller. O rótulo
 * vem, nesta ordem:
 *
 * 1. `VeterinarySpecialtyCatalog` — a mesma fonte que semeia `specialties` e que
 *    `ProfessionalOfferingMatrix` usa para decidir quem pode declarar o quê;
 * 2. o rótulo do `ProfessionalType` do conceito ("Creche e Hotel", "Clínica Veterinária");
 * 3. o rótulo da `ServiceCategory` do conceito ("Vacinação", "Emergência");
 * 4. o próprio termo canônico com iniciais maiúsculas — último recurso, nunca alcançado
 *    pelos conceitos que existem hoje.
 *
 * A ordem é a da especificidade: "Diagnostico por Imagem" é mais preciso que "Exames de
 * Imagem" (a categoria), e "Medicina Felina" não tem tipo nem categoria nenhuma.
 */
final class SearchConceptPresenter
{
    /** @var array<string, string>|null conceito → rótulo do catálogo de especialidades */
    private ?array $specialtyLabelsByConcept = null;

    public function __construct(private readonly SearchVocabulary $vocabulary) {}

    /**
     * A forma canônica PRIMÁRIA do conceito — a primeira de `terms`, que é a mais descritiva
     * ("diagnostico por imagem", não "imagem").
     *
     * É garantidamente um alias do próprio conceito, então reenviá-la como `?query=` ou como
     * `?specialty[]=` volta a cair aqui. Essa garantia é o que permite o cliente tratar
     * `term`/`value` como opaco.
     */
    public function termFor(SearchConcept $concept): string
    {
        return $concept->storedForms()[0];
    }

    public function labelFor(SearchConcept $concept): string
    {
        return $this->specialtyLabels()[$concept->key]
            ?? $concept->professionalType?->label()
            ?? $concept->serviceCategory?->label()
            ?? $this->titleCase($this->termFor($concept));
    }

    /**
     * As opções do filtro `?specialty[]=`, uma por linha do catálogo veterinário.
     *
     * O `value` é o termo canônico do conceito (e não o nome do catálogo) porque é a forma
     * que `SearchVocabulary::storedFormsFor()` resolve sem depender de acento, barra ou
     * caixa. Quando o nome do catálogo não cai em conceito nenhum — hoje não acontece com
     * nenhuma das 21 linhas, e o teste cobre isso — o `value` degrada para o nome
     * normalizado, que o filtro ainda casa de forma literal.
     *
     * @return list<array{value: string, label: string}>
     */
    public function specialtyFilterOptions(): array
    {
        return array_map(
            fn (array $specialty): array => [
                'value' => $this->specialtyFilterValue($specialty['name']),
                'label' => $specialty['label'],
            ],
            VeterinarySpecialtyCatalog::all(),
        );
    }

    private function specialtyFilterValue(string $catalogName): string
    {
        $concept = $this->vocabulary->conceptFor($catalogName);

        return $concept !== null
            ? $this->termFor($concept)
            : $this->vocabulary->storedFormsFor($catalogName)[0];
    }

    /**
     * @return array<string, string>
     */
    private function specialtyLabels(): array
    {
        return $this->specialtyLabelsByConcept ??= $this->buildSpecialtyLabels();
    }

    /**
     * @return array<string, string>
     */
    private function buildSpecialtyLabels(): array
    {
        $labels = [];

        foreach (VeterinarySpecialtyCatalog::all() as $specialty) {
            $concept = $this->vocabulary->conceptFor($specialty['name']);

            if ($concept !== null) {
                $labels[$concept->key] = $specialty['label'];
            }
        }

        return $labels;
    }

    private function titleCase(string $term): string
    {
        return mb_convert_case($term, MB_CASE_TITLE, 'UTF-8');
    }
}
