<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;
use PDOException;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Chave arbitrária do advisory lock de sessão do Postgres que protege o banco de
     * teste compartilhado (`twopets_test`) contra duas suítes rodando ao mesmo tempo.
     *
     * `RefreshDatabase` roda `migrate:fresh` (dropa e recria as 93 tabelas) uma vez por
     * processo e depois embrulha cada teste numa transação com rollback. Como o banco é
     * único e compartilhado, uma segunda suíte concorrente dispara o próprio
     * `migrate:fresh` no meio da primeira: o schema desaparece e reaparece sob os pés dos
     * testes em andamento. O sintoma é `QueryException` do tipo "relation ... does not
     * exist" em arquivos sem nenhuma relação com a mudança feita, com uma contagem de
     * falhas que muda a cada corrida sem nenhuma edição de código — visto e documentado
     * em `.claude/agent-memory/backend-specialist/baseline-testes.md`.
     */
    private const TEST_DATABASE_LOCK_KEY = 727419;

    /**
     * Mantida viva de propósito: um advisory lock de sessão do Postgres só existe
     * enquanto a conexão que o pediu estiver aberta. Nunca lida depois de atribuída —
     * o valor em si não importa, só o fato de o objeto continuar referenciado.
     */
    private static ?PDO $testDatabaseLockConnection = null;

    private static bool $testDatabaseLockAttempted = false;

    protected function setUp(): void
    {
        $this->guardAgainstConcurrentTestRuns();

        parent::setUp();
    }

    /**
     * Recusa a suíte imediatamente, com uma mensagem acionável, se outro processo já
     * segura o advisory lock do banco `twopets_test` — em vez de deixar o
     * `migrate:fresh` concorrente produzir falhas fantasma indistinguíveis de bug real.
     *
     * A conexão do lock é própria (fora do `DatabaseManager` do Laravel) e fica aberta
     * pela vida inteira do processo do PHPUnit: um advisory lock de sessão do Postgres
     * é liberado automaticamente quando a conexão fecha, então não precisa de unlock
     * explícito — só não pode ser a mesma conexão que o `RefreshDatabase` desconecta a
     * cada teste.
     */
    private function guardAgainstConcurrentTestRuns(): void
    {
        if (self::$testDatabaseLockAttempted) {
            return;
        }

        self::$testDatabaseLockAttempted = true;

        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            return;
        }

        $pdo = $this->connectDirectlyToTestDatabase();

        if ($pdo === null) {
            return;
        }

        $this->acquireLockOrThrow($pdo);
    }

    /**
     * Tenta o advisory lock nesta conexão dedicada; lança uma exceção clara se outro
     * processo já o segura, em vez de deixar o `RefreshDatabase` seguir para um
     * `migrate:fresh` que vai colidir com o da outra suíte.
     */
    private function acquireLockOrThrow(PDO $pdo): void
    {
        $lockAcquired = (bool) $pdo->query(
            'SELECT pg_try_advisory_lock('.self::TEST_DATABASE_LOCK_KEY.')'
        )->fetchColumn();

        if (! $lockAcquired) {
            throw new RuntimeException(
                'Outra execução de teste já está usando o banco "twopets_test" agora. '.
                'O RefreshDatabase migra e reseta esse banco a cada teste, então duas '.
                'suítes simultâneas corrompem os resultados uma da outra. Aguarde a '.
                'outra sessão terminar antes de rodar `php artisan test` de novo.'
            );
        }

        self::$testDatabaseLockConnection = $pdo;
    }

    /**
     * Conexão PDO crua, fora do `DatabaseManager`, lida diretamente das variáveis de
     * ambiente do `phpunit.xml`/`.env` — nesta fase do boot (`setUp()` antes de
     * `parent::setUp()`) o container do Laravel ainda não existe, então `config()` e
     * `DB::` não estão disponíveis.
     */
    private function connectDirectlyToTestDatabase(): ?PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '5432';
        $database = getenv('DB_TEST_DATABASE') ?: 'twopets_test';
        $username = getenv('DB_USERNAME') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';

        try {
            return new PDO(
                "pgsql:host={$host};port={$port};dbname={$database}",
                $username,
                $password
            );
        } catch (PDOException) {
            // Sem conexão não há como checar o lock; deixa o RefreshDatabase seguir e
            // falhar com o próprio erro de conexão, que já é claro por si só.
            return null;
        }
    }
}
