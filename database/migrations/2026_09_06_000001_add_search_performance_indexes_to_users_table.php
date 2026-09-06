<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 do plano de otimizacao — dominio "users / busca".
 *
 * `CONCURRENTLY` nao pode rodar dentro de uma transacao.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->createVisibleProfessionalIndexes();
        $this->createServiceIndexes();
        $this->createProfessionalUserIdIndex();
        $this->increaseStatisticsTargets();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users ALTER COLUMN role SET STATISTICS -1');
        DB::statement('ALTER TABLE users ALTER COLUMN registration_status SET STATISTICS -1');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_professionals_user_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_services_professional_active_category');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_services_professional_active_price');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_users_visible_professional_name');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_users_visible_professional_location');
    }

    /**
     * O indice mais importante do projeto. Query base:
     * app/Services/Search/ProfessionalSearchService.php::buildBaseQuery() (linhas 79-84) +
     * applyLocationFilter() (111-125) — todo request de busca com localizacao passa por aqui,
     * e SEMPRE tem o predicado ST_DWithin. GIST parcial em vez de composto btree porque o
     * filtro geografico e obrigatorio nesse caminho: guarda so ~35k linhas (profissionais
     * visiveis) em vez das ~200k de `idx_users_location`, e elimina o `Filter` pos-bitmap que
     * hoje descarta ~28k linhas (ver .claude/agent-memory/backend-specialist/benchmark-seeder.md).
     *
     * O irmao em (name) serve o caminho SEM localizacao — sortByDistance() (linha 284-294)
     * cai em orderBy('users.name') quando hasLocation() e falso.
     *
     * RISCO (documentado tambem no plano, secao "Riscos", item 1): o predicado abaixo tem
     * que ser TEXTUALMENTE identico ao WHERE de buildBaseQuery(). Se registration_status
     * ganhar um novo valor "visivel" no futuro (hoje so 'approved') e buildBaseQuery() for
     * atualizado sem atualizar este indice, o Postgres para de casar o predicado EM SILENCIO —
     * sem erro, so uma busca que volta a fazer bitmap scan sobre idx_users_location (~200k
     * linhas) em vez de index scan direto sobre este (~35k). Qualquer mudanca nesses quatro
     * filtros exige migration nova aqui. Trava automatizada (teste que asserta o nome do indice
     * no plano do EXPLAIN) e item do plano, ainda nao implementado nesta fase.
     */
    private function createVisibleProfessionalIndexes(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_visible_professional_location
            ON users USING GIST (location)
            WHERE role = 'professional'
              AND profile_completed = true
              AND registration_status = 'approved'
              AND is_suspended = false
              AND deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_visible_professional_name
            ON users USING BTREE (name)
            WHERE role = 'professional'
              AND profile_completed = true
              AND registration_status = 'approved'
              AND is_suspended = false
              AND deleted_at IS NULL
            SQL);
    }

    /**
     * services.professional_id nao tinha NENHUM indice (so services_location_id_index).
     * Tabela com 180k linhas no benchmark (benchmark-seeder.md), e e a mais consultada da
     * busca depois de users:
     *
     * - ProfessionalSearchService::sortByPriceLow()  (linhas 323-330): MIN(price) correlacionado
     *   por services.professional_id = users.id + services.active = true.
     * - ProfessionalSearchService::sortByPriceHigh() (linhas 332-344): idem, MAX(price).
     * - ProfessionalSearchService::applyPriceRangeFilter() (150-165): whereHas('professional.services',
     *   price >= / <=), mesma correlacao por professional_id (via user_id).
     * - ProfessionalSearchService::applyServiceCategoryFilter() (138-148): whereHas('professional.services',
     *   where('category', ...)->where('active', true)).
     *
     * Dois indices parciais (WHERE active = true, o predicado que toda essa query ja usa) em
     * vez de um unico indice largo: (professional_id, price) serve MIN/MAX com index-only
     * scan; (professional_id, category) serve o filtro de categoria. services.professional_id
     * tambem e FK real com ON DELETE CASCADE para users (migration
     * 2025_11_22_235300_create_services_table.php:12).
     */
    private function createServiceIndexes(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_services_professional_active_price
            ON services (professional_id, price)
            WHERE active = true
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_services_professional_active_category
            ON services (professional_id, category)
            WHERE active = true
            SQL);
    }

    /**
     * professionals.user_id nao tinha indice lider (so (professional_type, user_id), onde
     * user_id e a 2a coluna — inutil para WHERE user_id = ? isolado). professionals e 1:1
     * com users e e correlacionado a CADA linha candidata da busca:
     *
     * - ProfessionalSearchService::applyProfessionalTypeFilter()  (linha 133)
     * - ProfessionalSearchService::applyServiceCategoryFilter()   (linha 144, via professional.services)
     * - ProfessionalSearchService::applyPriceRangeFilter()        (linha 156)
     * - ProfessionalSearchService::applyRatingFilter()            (linha 173)
     * - ProfessionalSearchService::sortByRating()                 (linha 303, whereColumn('professionals.user_id','users.id'))
     * - ProfessionalSearchService::applySearchQuery()              (linha 244, orWhereHas('professional', ...))
     */
    private function createProfessionalUserIdIndex(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_professionals_user_id ON professionals (user_id)');
    }

    /**
     * role e registration_status tem poucos valores distintos com distribuicao enviesada
     * (seed: 80/15/5 em registration_status; role e ~22,5% 'professional', resto 'tutor'/'admin'
     * — ver benchmark-seeder.md). O planner default (100 buckets de estatistica) subestima a
     * seletividade combinada desses dois filtros dentro de buildBaseQuery(). ANALYZE roda
     * fora desta migration, no runbook de verificacao da fase (precisa acontecer depois que o
     * volume de benchmark ja esta carregado).
     */
    private function increaseStatisticsTargets(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN role SET STATISTICS 500');
        DB::statement('ALTER TABLE users ALTER COLUMN registration_status SET STATISTICS 500');
    }
};
