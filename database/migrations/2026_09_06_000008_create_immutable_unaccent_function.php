<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 5 do plano de otimização — busca fuzzy (trigram/pg_trgm), passo 1: wrapper IMMUTABLE.
 *
 * `unaccent(text)` (1 argumento) é STABLE, não IMMUTABLE — confirmado em produção via
 * `SELECT provolatile FROM pg_proc WHERE proname = 'unaccent'` (retorna 's'). Ele resolve o
 * dicionário de acentuação via `search_path` em tempo de execução, então em teoria o mesmo
 * input poderia produzir saída diferente entre sessões. Um índice de expressão só aceita
 * função marcada IMMUTABLE.
 *
 * A forma de 2 argumentos, com o dicionário fixado como `regdictionary`
 * (`'public.unaccent'::regdictionary`), remove essa dependência de `search_path` em runtime
 * e é legitimamente imutável — é exatamente o wrapper abaixo. `public.` qualifica tudo
 * (função e dicionário) para não depender do `search_path` de quem chama.
 *
 * ATENÇÃO — documentado aqui porque é o único lugar que alguém vai ler antes de mexer nisso:
 * se algum dia rodar `ALTER TEXT SEARCH DICTIONARY unaccent ...` para trocar as regras de
 * acentuação, TODOS os índices GIN criados em cima desta função (migration
 * `2026_09_06_000009_add_trigram_search_indexes.php`) ficam silenciosamente desatualizados —
 * sem erro nenhum, só resultado errado (falso positivo/negativo na busca) até alguém rodar
 * `REINDEX INDEX CONCURRENTLY <nome_do_indice>` manualmente em cada um. É o trade-off aceito
 * da técnica (ver plano de otimização, Fase 5 + seção "Riscos", item 2) — não um bug.
 *
 * A string literal abaixo tem que bater com
 * `App\Services\Search\FuzzyMatchExpressionBuilder::IMMUTABLE_UNACCENT_FUNCTION`: é o nome de
 * função que a query em `ProfessionalSearchService` e os índices desta fase usam.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.immutable_unaccent(text)
            RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS
            $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Sem CASCADE de propósito: se os índices da migration 2026_09_06_000009 ainda
        // existirem, este DROP falha com um erro claro em vez de apagá-los em silêncio. A
        // ordem correta (índices primeiro, função depois) é a que o `migrate:rollback`
        // já segue sozinho, por ser a migration mais recente das duas.
        DB::statement('DROP FUNCTION IF EXISTS public.immutable_unaccent(text)');
    }
};
