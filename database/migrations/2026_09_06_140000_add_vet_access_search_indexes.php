<?php

use App\Services\Search\FuzzyMatchExpressionBuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices do fluxo "veterinário solicita acesso a um pet".
 *
 * `pet_vet_accesses` nasceu sem nenhum índice em `pet_id`: a FK do Postgres NÃO cria índice
 * no lado que referencia, então `GET /pet-vet-access/pet/{petId}` e a lista de pendências do
 * tutor varriam a tabela inteira.
 *
 * `CONCURRENTLY` não roda dentro de transação — daí `$withinTransaction = false`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->createAccessLookupIndexes();
        $this->createNameTrigramIndexes();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_users_name_unaccent_trgm_live');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_pets_name_unaccent_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_pet_vet_accesses_vet_granted_live');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_pet_vet_accesses_pet_id');
    }

    /**
     * `idx_pet_vet_accesses_pet_id` serve dois caminhos: a listagem de acessos de um pet
     * (todos os status, então não pode ser parcial) e o filtro por pet da lista de pendências
     * do tutor.
     *
     * `idx_pet_vet_accesses_vet_granted_live` cobre a carteira de pacientes do vet inteira —
     * predicado idêntico ao do scope `PetVetAccess::active()` e chave de ordenação incluída,
     * então a paginação sai por Index Scan sem nó de Sort.
     */
    private function createAccessLookupIndexes(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_pet_vet_accesses_pet_id
            ON pet_vet_accesses (pet_id)
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_pet_vet_accesses_vet_granted_live
            ON pet_vet_accesses (veterinarian_id, granted_at DESC)
            WHERE status = 'accepted'
              AND is_active = true
              AND revoked_at IS NULL
            SQL);
    }

    /**
     * GIN trigram para a busca textual da carteira de pacientes (`?q=` em `/my-patients`),
     * um índice por lado do OR — `BitmapOr` entre índices de tabelas diferentes não existe
     * no Postgres, então cada subconsulta de `PatientSearchFilter` precisa do seu.
     *
     * As expressões precisam ser TEXTUALMENTE idênticas às que `FuzzyMatchExpressionBuilder`
     * gera; se divergirem, o Postgres para de casar o índice EM SILÊNCIO e a busca volta a
     * varrer as 300 mil linhas de `pets` / 200 mil de `users`. Por isso o nome da função vem
     * da mesma constante que a aplicação usa.
     *
     * `idx_users_name_unaccent_trgm_live` se sobrepõe de propósito ao
     * `idx_users_name_unaccent_trgm` da migration 2026_09_06_000009: aquele é PARCIAL em
     * profissionais visíveis, e um índice parcial só é elegível quando o predicado dele está
     * implícito na query. A busca por nome de TUTOR não tem (nem pode ter) esse predicado —
     * medido: sem este índice o lado dos tutores vira `Parallel Seq Scan` de ~700 ms sobre
     * `users`. O parcial continua existindo porque é bem menor e segue sendo o escolhido na
     * busca pública de profissionais, que é o caminho mais quente do produto.
     */
    private function createNameTrigramIndexes(): void
    {
        $function = FuzzyMatchExpressionBuilder::IMMUTABLE_UNACCENT_FUNCTION;

        DB::statement(<<<SQL
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_pets_name_unaccent_trgm
            ON pets USING GIN ({$function}(name) gin_trgm_ops)
            WHERE deleted_at IS NULL
            SQL);

        DB::statement(<<<SQL
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_name_unaccent_trgm_live
            ON users USING GIN ({$function}(name) gin_trgm_ops)
            WHERE deleted_at IS NULL
            SQL);
    }
};
