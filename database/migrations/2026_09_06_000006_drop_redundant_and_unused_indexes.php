<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 do plano de otimizacao — descarte de indices redundantes ou comprovadamente nao
 * usados. Indice tem custo permanente de escrita e VACUUM; manter um que nunca casa com
 * nenhuma query e puro custo.
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

        $this->dropRedundantUniqueSiblings();
        $this->dropUselessGeoIndex();
        $this->dropUnmatchableTrigramIndex();
        $this->dropDuplicatePetsPublicIdConstraint();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->recreateDuplicatePetsPublicIdConstraint();
        $this->recreateUnmatchableTrigramIndex();
        $this->recreateUselessGeoIndex();
        $this->recreateRedundantUniqueSiblings();
    }

    /**
     * Cada uma destas colunas ja tem um indice UNIQUE cuja coluna lider e a mesma coluna do
     * indice nao-unico ao lado — o nao-unico nunca e escolhido pelo planner (o unique serve
     * qualquer busca por igualdade igual ou melhor, por ja garantir cardinalidade 1) e so
     * paga custo de escrita/VACUUM em dobro:
     *
     * - email_verification_throttles_email_index      (existe email_verification_throttles_email_unique)
     * - orders_order_number_index                      (existe orders_order_number_unique)
     * - insurance_claims_claim_number_index             (existe insurance_claims_claim_number_unique)
     * - referrals_referral_code_index                   (existe referrals_referral_code_unique)
     * - breeds_species_index                            (existe breeds_species_name_unique, species e a 1a coluna)
     * - favorites_user_id_index                         (existe favorites_user_id_professional_id_unique, user_id e a 1a coluna)
     */
    private function dropRedundantUniqueSiblings(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS email_verification_throttles_email_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS orders_order_number_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS insurance_claims_claim_number_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS referrals_referral_code_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS breeds_species_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS favorites_user_id_index');
    }

    private function recreateRedundantUniqueSiblings(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS email_verification_throttles_email_index ON email_verification_throttles (email)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_order_number_index ON orders (order_number)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS insurance_claims_claim_number_index ON insurance_claims (claim_number)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS referrals_referral_code_index ON referrals (referral_code)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS breeds_species_index ON breeds (species)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS favorites_user_id_index ON favorites (user_id)');
    }

    /**
     * lost_pet_alerts_last_seen_latitude_last_seen_longitude_index e um btree comum sobre
     * duas colunas double precision. Nenhuma query do projeto faz igualdade/range exato
     * nessas duas colunas juntas (a busca de pet perdido usa Haversine calculado em memoria —
     * ver app/Services/LostPet/LostPetAlertService.php, correcao para PostGIS e a Fase 6 do
     * plano, fora do escopo desta fase). Um btree (lat, lng) nao serve nenhuma forma de busca
     * geografica por raio — so seria util para igualdade exata de coordenada, que ninguem
     * faz. Puro custo.
     */
    private function dropUselessGeoIndex(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS lost_pet_alerts_last_seen_latitude_last_seen_longitude_index');
    }

    private function recreateUselessGeoIndex(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS lost_pet_alerts_last_seen_latitude_last_seen_longitude_index ON lost_pet_alerts (last_seen_latitude, last_seen_longitude)');
    }

    /**
     * idx_professionals_business_name_trgm indexa `business_name` puro, mas
     * ProfessionalSearchService::applySearchQuery() (linhas 228-270) busca
     * `similarity(unaccent(business_name), unaccent(?))` e `unaccent(business_name) ILIKE ...`
     * — uma expressao diferente da indexada. Um indice GIN trigram so casa com a EXPRESSAO
     * textualmente indexada; `unaccent(business_name)` nunca bate com `business_name`, entao
     * este indice nunca foi usado por essa query (nunca aparecera com idx_scan > 0 no portao
     * de saida desta fase). A Fase 5 do plano cria o substituto correto
     * (GIN sobre `public.immutable_unaccent(business_name)`, com wrapper IMMUTABLE) — fora do
     * escopo desta fase (indices de trigram/fuzzy search).
     */
    private function dropUnmatchableTrigramIndex(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_professionals_business_name_trgm');
    }

    private function recreateUnmatchableTrigramIndex(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_professionals_business_name_trgm ON professionals USING GIN (business_name gin_trgm_ops)');
    }

    /**
     * pets.public_id tem DOIS unique constraints apontando pra mesma coluna, criados em
     * migrations diferentes: `idx_pets_public_id_unique`
     * (database/migrations/2025_12_27_204000_add_public_id_to_pets.php) e
     * `pets_public_id_unique` (database/migrations/2026_04_08_100000_add_performance_indexes_to_pets_table.php).
     * Confirmado via `pg_constraint` (contype='u' nos dois, nao e so indice) — por isso o
     * descarte usa `ALTER TABLE ... DROP CONSTRAINT`, nao `DROP INDEX`. Mantido
     * `pets_public_id_unique` (nome gerado pela convencao padrao do Laravel) e removido
     * `idx_pets_public_id_unique` (nome manual, redundante).
     */
    private function dropDuplicatePetsPublicIdConstraint(): void
    {
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS idx_pets_public_id_unique');
    }

    /**
     * Reanexa o constraint via um indice unico criado CONCURRENTLY (evita o lock longo de um
     * `ADD CONSTRAINT ... UNIQUE` puro, que reconstruiria o indice sob ACCESS EXCLUSIVE).
     */
    private function recreateDuplicatePetsPublicIdConstraint(): void
    {
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS idx_pets_public_id_unique ON pets (public_id)');
        DB::statement('ALTER TABLE pets ADD CONSTRAINT idx_pets_public_id_unique UNIQUE USING INDEX idx_pets_public_id_unique');
    }
};
