<?php

use App\Services\Search\FuzzyMatchExpressionBuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices GIN trigram dos campos que a busca passou a cobrir: especialidades do profissional
 * e nome/descrição dos serviços oferecidos.
 *
 * Antes disso, `?query=fisioterapia` devolvia zero mesmo com a especialidade existindo no
 * catálogo — a busca textual só olhava `users.name`, `professionals.business_name` e
 * `professionals.description`. Sem índice aqui, cobrir os campos novos significaria trocar
 * "não acha" por "acha fazendo Seq Scan", que não é conserto.
 *
 * A expressão indexada tem que ser TEXTUALMENTE a mesma que `FuzzyMatchExpressionBuilder`
 * gera — por isso os dois lados leem `IMMUTABLE_UNACCENT_FUNCTION` da mesma constante. Se
 * divergir, o Postgres para de casar o índice EM SILÊNCIO (sem erro, só lentidão).
 *
 * Os mesmos índices servem `%>` (word_similarity), `%>>` (strict) e `%` — verificado com
 * EXPLAIN: `Index Cond`, não `Filter`. A condição para isso é a COLUNA ficar à esquerda do
 * operador; com a agulha à esquerda o predicado é equivalente em resultado e o índice não é
 * usado. `CONCURRENTLY` não roda dentro de transação.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->createSpecialtiesIndex();
        $this->createServiceIndexes();
        $this->createServiceCategoryIndex();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_services_category_professional');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_services_description_unaccent_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_services_name_unaccent_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_professionals_specialties_unaccent_trgm');
    }

    /**
     * `professionals.specialties` é TEXT com um array JSON dentro. Indexar o texto cru é
     * proposital e não preguiça: o trigrama ignora a pontuação do JSON e trata `_`, `/` e
     * espaço como separador, então a MESMA entrada de índice serve para as três taxonomias
     * que convivem na coluna hoje (`["Clínica Geral"]`, `["clinica_geral"]`, `["general"]`).
     * Uma normalização estrutural (tabela pivô) tornaria este índice desnecessário — e é o
     * caminho certo a longo prazo, registrado como dívida.
     */
    private function createSpecialtiesIndex(): void
    {
        $function = FuzzyMatchExpressionBuilder::IMMUTABLE_UNACCENT_FUNCTION;

        DB::statement(<<<SQL
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_professionals_specialties_unaccent_trgm
            ON professionals USING GIN ({$function}(specialties) gin_trgm_ops)
            WHERE specialties IS NOT NULL
            SQL);
    }

    /**
     * Predicado parcial idêntico ao do EXISTS de `ProfessionalTextSearchQuery::matchServices()`
     * (`active = true AND deleted_at IS NULL`): serviço inativo ou removido nunca entra na
     * busca, então indexá-lo só gastaria espaço e VACUUM. Se aquele EXISTS deixar de filtrar
     * por um dos dois, o Postgres não consegue mais provar que o índice cobre a consulta e
     * para de usá-lo — sem aviso.
     */
    private function createServiceIndexes(): void
    {
        $function = FuzzyMatchExpressionBuilder::IMMUTABLE_UNACCENT_FUNCTION;

        DB::statement(<<<SQL
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_services_name_unaccent_trgm
            ON services USING GIN ({$function}(name) gin_trgm_ops)
            WHERE active = true AND deleted_at IS NULL
            SQL);

        DB::statement(<<<SQL
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_services_description_unaccent_trgm
            ON services USING GIN ({$function}(description) gin_trgm_ops)
            WHERE description IS NOT NULL AND active = true AND deleted_at IS NULL
            SQL);
    }

    /**
     * O índice existente `idx_services_professional_active_category` tem `professional_id`
     * como primeira coluna, então NÃO serve uma busca por categoria isolada — que é
     * exatamente o que a busca por conceito faz ("banho e tosa" vira
     * `category = 'grooming'`). `(category, professional_id)` permite Index Only Scan:
     * a subquery só precisa das duas colunas.
     */
    private function createServiceCategoryIndex(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_services_category_professional
            ON services (category, professional_id)
            WHERE active = true AND deleted_at IS NULL
            SQL);
    }
};
