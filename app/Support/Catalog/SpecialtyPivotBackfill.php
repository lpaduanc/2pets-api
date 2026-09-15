<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Preenche `professional_specialty` a partir do que já está gravado em
 * `professionals.specialties` (TEXT com JSON dentro).
 *
 * ── Por que existe como classe, e não só como SQL dentro da migration ─────────────────
 * Porque tem dois consumidores: a migration que popula a base atual UMA vez e os seeders,
 * que escrevem `professionals` em lote por `insert` — sem evento Eloquent, logo sem passar
 * pelo observer que mantém a pivô em dia. Duas cópias do mesmo SQL divergiriam, e a
 * divergência apareceria como filtro de especialidade devolvendo menos em dev do que em
 * produção.
 *
 * ── A regra de casamento ──────────────────────────────────────────────────────────────
 * Rótulo gravado e `specialties.name` são comparados NORMALIZADOS — minúsculas, sem acento,
 * e `_`, `-`, `/` viram espaço. É a mesma normalização que `ProfessionalAttributeFilter` faz
 * na consulta, e é o que põe `Clínica Geral`, `clinica_geral`, `Clinica Geral` e
 * `Fisioterapia/Reabilitacao` na mesma chave sem depender de como cada seed escreveu.
 *
 * Rótulo que não casa com nenhuma linha do catálogo NÃO é descartado em silêncio: volta na
 * contagem de `unmapped` para quem chamou decidir o que fazer. Backfill silencioso é a forma
 * clássica de perder dado e só descobrir meses depois, pela busca que devolve de menos.
 */
final class SpecialtyPivotBackfill
{
    private const PIVOT_TABLE = 'professional_specialty';

    /**
     * @return array{linked: int, unmapped: array<string, int>}
     */
    public function run(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return ['linked' => 0, 'unmapped' => []];
        }

        return ['linked' => $this->insertMatches(), 'unmapped' => $this->unmappedLabels()];
    }

    /**
     * `ON CONFLICT DO NOTHING` torna a operação repetível: rodar de novo depois de um seed
     * parcial acrescenta o que falta em vez de estourar na chave única.
     */
    private function insertMatches(): int
    {
        return DB::affectingStatement(
            'INSERT INTO '.self::PIVOT_TABLE.' (professional_id, specialty_id, created_at, updated_at)
             SELECT DISTINCT labels.professional_id, specialties.id, now(), now()
             FROM ('.$this->resolvedLabelsSql().') AS labels
             JOIN specialties ON '.$this->normalizedExpression('specialties.name').' = labels.resolved_label
             ON CONFLICT DO NOTHING',
            $this->aliasBindings(),
        );
    }

    /**
     * @return array<string, int>
     */
    private function unmappedLabels(): array
    {
        $rows = DB::select(
            'SELECT labels.raw_label, count(DISTINCT labels.professional_id) AS professionals
             FROM ('.$this->resolvedLabelsSql().') AS labels
             WHERE NOT EXISTS (
                 SELECT 1 FROM specialties
                 WHERE '.$this->normalizedExpression('specialties.name').' = labels.resolved_label
             )
             GROUP BY labels.raw_label
             ORDER BY professionals DESC',
            $this->aliasBindings(),
        );

        $unmapped = [];

        foreach ($rows as $row) {
            $unmapped[(string) $row->raw_label] = (int) $row->professionals;
        }

        return $unmapped;
    }

    /**
     * O rótulo gravado já traduzido pelos aliases do cliente (`cardiology` → `Cardiologia`),
     * na mesma forma normalizada com que `specialties.name` é comparado.
     *
     * Precisa existir AQUI, e não só no PHP: o backfill é o caminho dos seeders e das
     * escritas em lote, que não passam pelo observer. Sem a tradução em SQL, uma linha
     * gravada com o slug do formulário viraria rótulo órfão — coluna preenchida, pivô vazia,
     * profissional invisível para o filtro de especialidade.
     */
    private function resolvedLabelsSql(): string
    {
        $aliasCount = count(SpecialtyAliasCatalog::canonicalNameByAlias());

        if ($aliasCount === 0) {
            return 'SELECT labels.professional_id, labels.raw_label, labels.normalized_label AS resolved_label
                    FROM ('.$this->normalizedLabelsSql().') AS labels';
        }

        return 'SELECT labels.professional_id, labels.raw_label,
                       COALESCE('.$this->normalizedExpression('aliases.canonical_name').', labels.normalized_label) AS resolved_label
                FROM ('.$this->normalizedLabelsSql().') AS labels
                LEFT JOIN ('.$this->aliasValuesSql($aliasCount).') AS aliases(alias, canonical_name)
                       ON '.$this->normalizedExpression('aliases.alias').' = labels.normalized_label';
    }

    /**
     * O mapa de aliases entra como VALUES parametrizado — vem de constante do código, mas
     * interpolar string em SQL é hábito que um dia encontra dado de usuário. O `::text`
     * existe porque o Postgres não infere o tipo de um parâmetro solto dentro de `VALUES`.
     */
    private function aliasValuesSql(int $aliasCount): string
    {
        return 'VALUES '.implode(', ', array_fill(0, $aliasCount, '(?::text, ?::text)'));
    }

    /**
     * @return list<string>
     */
    private function aliasBindings(): array
    {
        $bindings = [];

        foreach (SpecialtyAliasCatalog::canonicalNameByAlias() as $alias => $canonicalName) {
            $bindings[] = $alias;
            $bindings[] = $canonicalName;
        }

        return $bindings;
    }

    /**
     * Um rótulo por linha. `jsonb_array_elements_text` em `CROSS JOIN LATERAL` explode o
     * array JSON; o `WHERE` de fora protege contra coluna nula e contra o raro `'[]'`.
     */
    private function normalizedLabelsSql(): string
    {
        return 'SELECT professionals.id AS professional_id,
                       label.value AS raw_label,
                       '.$this->normalizedExpression('label.value')." AS normalized_label
                FROM professionals
                CROSS JOIN LATERAL jsonb_array_elements_text(professionals.specialties::jsonb) AS label(value)
                WHERE professionals.specialties IS NOT NULL
                  AND professionals.deleted_at IS NULL
                  AND jsonb_typeof(professionals.specialties::jsonb) = 'array'";
    }

    /**
     * Precisa casar EXATAMENTE com `SearchTextNormalizer::normalize()`, que é quem o caminho
     * de escrita usa para resolver o rótulo contra o catálogo. `regexp_replace` (e não o
     * `translate` mais barato de `ProfessionalAttributeFilter`) porque o PHP COLAPSA
     * separadores seguidos num espaço só: sem colapsar aqui, um rótulo como
     * "Cardiologia - Pediatrica" viraria "cardiologia   pediatrica" no SQL e
     * "cardiologia pediatrica" no PHP, e os dois lados deixariam de se encontrar. Isto roda
     * uma vez por backfill; o custo não importa, a coerência sim.
     */
    private function normalizedExpression(string $column): string
    {
        return "btrim(regexp_replace(lower(public.immutable_unaccent({$column})), '[^a-z0-9]+', ' ', 'g'))";
    }
}
