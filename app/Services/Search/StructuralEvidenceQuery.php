<?php

namespace App\Services\Search;

use App\DataTransferObjects\Search\SearchConcept;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Os ramos ESTRUTURAIS da busca: comparação de coluna, sem fuzzy e sem falso-positivo
 * possível. Cobre a camada 2 (`categoria`, `services_offered`, `equipment`) e a camada 3
 * (`professional_type`) de `RelevanceLayer`.
 *
 * Separado de `ProfessionalTextSearchQuery` porque cada ramo aqui vai numa subquery PRÓPRIA,
 * e isso é decisão de plano medida: o `BitmapOr` entre índices GIN só acontece quando TODOS
 * os ramos do OR são servidos por índice do mesmo tipo. Basta um `category = 'grooming'` no
 * meio dos ramos trigram para o Postgres desistir e varrer a tabela aplicando o OR inteiro
 * como filtro — medido em `services` (180k linhas), "banho e tosa": 2.947 ms misturado
 * contra 187 ms separado, mesmo resultado.
 */
final class StructuralEvidenceQuery
{
    /**
     * Camada 2 — quem tem serviço ATIVO na categoria do conceito. É a evidência que faz
     * "banho e tosa" encontrar quem realmente presta banho e tosa, independentemente de como
     * o serviço foi nomeado.
     */
    public function orMatchServiceCategory(Builder $group, SearchConcept $concept): void
    {
        if ($concept->serviceCategory === null) {
            return;
        }

        $group->orWhereIn('users.id', fn (QueryBuilder $sub) => $sub
            ->select('services.professional_id')
            ->from('services')
            ->whereRaw('services.active = true')
            ->whereNull('services.deleted_at')
            ->where('services.category', $concept->serviceCategory->value));
    }

    /**
     * Camada 2 — quem DECLAROU no cadastro que presta aquilo, mesmo sem ter criado a linha de
     * serviço ainda, ou quem tem o equipamento correspondente.
     *
     * É o que responde à consequência de produto da regra de qualificação: um profissional
     * recém-cadastrado não some das buscas conceituais, porque o cadastro (Onda 3) obriga a
     * declarar `services_offered` de forma granular. Ver `ConceptStructuralEvidence`.
     *
     * `@>` (contenção jsonb) e não o operador `?|`: `?` colide com o placeholder de binding do
     * PDO. `@>` é indexável pelo GIN jsonb criado na migration `2026_09_22_100001`, e uma
     * agulha por item mantém cada predicado indexável — `jsonb_exists_any()` na forma
     * funcional NÃO casa índice (o planner casa OPERADOR, não função).
     */
    public function orMatchDeclaredOffering(Builder $group, SearchConcept $concept): void
    {
        $declared = ConceptStructuralEvidence::declaredServiceValues($concept);
        $equipment = ConceptStructuralEvidence::equipmentValues($concept);

        if ($declared === [] && $equipment === []) {
            return;
        }

        $group->orWhereIn('users.id', fn (QueryBuilder $sub) => $sub
            ->select('professionals.user_id')
            ->from('professionals')
            ->whereNull('professionals.deleted_at')
            ->where(function (QueryBuilder $columns) use ($declared, $equipment): void {
                $this->orContains($columns, 'professionals.services_offered', $declared);
                $this->orContains($columns, 'professionals.equipment', $equipment);
            }));
    }

    /**
     * Camada 3 — `professional_type`. Só é chamado no regime ABERTO (conceito que é só tipo
     * de negócio, como "clínica" ou "petshop"). Para conceito com categoria de serviço, o
     * tipo ranqueia mas nunca qualifica: é promessa de cadastro, e foi exatamente por
     * qualificar sozinho que a busca por "banho e tosa" devolvia um hospital veterinário.
     */
    public function orMatchProfessionalType(Builder $group, SearchConcept $concept): void
    {
        if ($concept->professionalType === null) {
            return;
        }

        $group->orWhereIn('users.id', fn (QueryBuilder $sub) => $sub
            ->select('professionals.user_id')
            ->from('professionals')
            ->whereNull('professionals.deleted_at')
            ->where('professionals.professional_type', $concept->professionalType->value));
    }

    /**
     * @param  list<string>  $values
     */
    private function orContains(QueryBuilder $group, string $column, array $values): void
    {
        foreach ($values as $value) {
            $group->orWhereRaw(
                "{$column}::jsonb @> ?::jsonb",
                [json_encode([$value], JSON_UNESCAPED_UNICODE)],
            );
        }
    }
}
