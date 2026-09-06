<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 5 do plano de otimização — busca fuzzy (trigram/pg_trgm), passo 3: threshold do
 * operador `%`.
 *
 * `pg_trgm.similarity_threshold` é a GUC que o operador `%` usa para decidir "é parecido o
 * suficiente" (default do Postgres é 0.3). Setar via `SET` dentro do request é frágil com
 * conexão persistente/pool — o Laravel reutiliza a mesma conexão PDO entre requests, então
 * um `SET` de uma request vaza para a próxima que reusar a conexão. `ALTER DATABASE ... SET`
 * fixa o valor de forma durável, sem custo por request.
 *
 * ATENÇÃO: isso muda o RECALL da busca fuzzy para QUALQUER query que use `%`/`<->` contra
 * este banco — é configuração de banco inteiro, não de uma feature isolada. 0.2 é o valor já
 * usado pela implementação anterior (constante `SIMILARITY_THRESHOLD`, removida de
 * `ProfessionalSearchService` porque o filtro agora é feito pelo operador `%`, que lê essa
 * GUC em vez de receber o valor por bind), mantido aqui para não alterar o comportamento de
 * busca já validado pelo produto.
 *
 * `ALTER DATABASE ... SET` só afeta conexões NOVAS — a conexão que está rodando esta própria
 * migration não é afetada retroativamente. Por isso o `SET` de sessão logo abaixo: garante
 * que a mesma conexão que acabou de rodar a migration (a que o `RefreshDatabase` da suíte
 * mantém aberta por todo o processo) também enxergue o novo threshold, sem depender de
 * reconectar.
 *
 * Roda contra o banco da CONEXÃO ATUAL (`DB::getDatabaseName()`), não um nome fixo — assim a
 * mesma migration aplica em `twopets` (dev) e em `twopets_test` (suíte, ver
 * `config/database.php` conexão `pgsql_test`). Os dois bancos precisam manter o mesmo recall,
 * senão o teste funcional de acento/erro de digitação (`tests/Feature/SearchTest.php`) valida
 * um comportamento que diverge do de produção.
 */
return new class extends Migration
{
    private const SIMILARITY_THRESHOLD = 0.2;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $database = DB::getDatabaseName();

        DB::statement("ALTER DATABASE \"{$database}\" SET pg_trgm.similarity_threshold = ".self::SIMILARITY_THRESHOLD);
        DB::statement('SET pg_trgm.similarity_threshold = '.self::SIMILARITY_THRESHOLD);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $database = DB::getDatabaseName();

        DB::statement("ALTER DATABASE \"{$database}\" RESET pg_trgm.similarity_threshold");
        DB::statement('RESET pg_trgm.similarity_threshold');
    }
};
