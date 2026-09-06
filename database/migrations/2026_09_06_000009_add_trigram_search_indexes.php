<?php

use App\Services\Search\FuzzyMatchExpressionBuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 5 do plano de otimização — busca fuzzy (trigram/pg_trgm), passo 2: índices GIN de
 * expressão. `CONCURRENTLY` não roda dentro de transação.
 *
 * A expressão indexada usa `public.immutable_unaccent()` (migration 2026_09_06_000008) e tem
 * que ser TEXTUALMENTE idêntica à expressão que `ProfessionalSearchService` gera via
 * `FuzzyMatchExpressionBuilder` — por isso os dois lados leem a mesma constante
 * `FuzzyMatchExpressionBuilder::IMMUTABLE_UNACCENT_FUNCTION`. Se a expressão divergir (outra
 * função, outro cast, ordem diferente de argumento), o Postgres simplesmente para de casar
 * o índice EM SILÊNCIO e a busca com termo volta a fazer seq scan sobre ~200k linhas — o
 * mesmo risco já documentado para `idx_users_visible_professional_location` (migration
 * 2026_09_06_000001).
 *
 * `idx_professionals_business_name_trgm`, que indexava `business_name` cru (a query busca
 * `unaccent(business_name)`, nunca casou — `idx_scan` sempre foi 0), foi dropado na Fase 4
 * (migration 2026_09_06_000006). Este é o substituto correto.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->createUsersNameIndex();
        $this->createProfessionalsIndexes();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_professionals_description_unaccent_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_professionals_business_name_unaccent_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_users_name_unaccent_trgm');
    }

    /**
     * Índice PARCIAL com o MESMO predicado de `idx_users_visible_professional_location`
     * (migration 2026_09_06_000001) e de `buildBaseQuery()` em
     * `app/Services/Search/ProfessionalSearchService.php` — todo profissional elegível para
     * a busca por nome já passa por esse filtro, então indexar as linhas fora dele só
     * desperdiçaria espaço e VACUUM sem nunca ser lido.
     *
     * Usada por `FuzzyMatchExpressionBuilder::fuzzyMatch('users.name')` (operador `%`) e
     * `ilikeMatch('users.name')` (fallback ILIKE, mesmo índice).
     */
    private function createUsersNameIndex(): void
    {
        $function = FuzzyMatchExpressionBuilder::IMMUTABLE_UNACCENT_FUNCTION;

        DB::statement(<<<SQL
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_name_unaccent_trgm
            ON users USING GIN ({$function}(name) gin_trgm_ops)
            WHERE role = 'professional'
              AND profile_completed = true
              AND registration_status = 'approved'
              AND is_suspended = false
              AND deleted_at IS NULL
            SQL);
    }

    /**
     * `professionals` não tem soft delete nem os flags de visibilidade de `users` — o único
     * predicado útil aqui é "coluna preenchida" (`IS NOT NULL`), que evita indexar NULL
     * (nunca participa de `%`/ILIKE de qualquer forma, já que a função é STRICT).
     *
     * Usadas por `FuzzyMatchExpressionBuilder::fuzzyMatch()`/`ilikeMatch()` contra
     * `p.business_name`/`p.description` em `ProfessionalSearchService::applyFuzzyMatchFilter()`.
     */
    private function createProfessionalsIndexes(): void
    {
        $function = FuzzyMatchExpressionBuilder::IMMUTABLE_UNACCENT_FUNCTION;

        DB::statement(<<<SQL
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_professionals_business_name_unaccent_trgm
            ON professionals USING GIN ({$function}(business_name) gin_trgm_ops)
            WHERE business_name IS NOT NULL
            SQL);

        DB::statement(<<<SQL
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_professionals_description_unaccent_trgm
            ON professionals USING GIN ({$function}(description) gin_trgm_ops)
            WHERE description IS NOT NULL
            SQL);
    }
};
