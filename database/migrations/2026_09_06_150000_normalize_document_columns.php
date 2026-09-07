<?php

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Cpf;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Regra de projeto: CPF e CNPJ são gravados como string limpa (só dígitos) em TODO o sistema,
 * e toda busca normaliza a entrada antes de consultar.
 *
 * As colunas tinham sido dimensionadas para o valor COM máscara (`users.cpf varchar(14)`,
 * `professionals.cnpj varchar(18)`, `users.cnpj`/`companies.cnpj varchar(255)`), ou seja, o
 * schema convidava a gravar máscara. Guardar máscara quebra três coisas de uma vez:
 *   - a busca (comparar `'123.456.789-00'` com `'12345678900'` nunca casa);
 *   - o índice único (o mesmo documento em dois formatos passa como se fosse diferente);
 *   - a indexabilidade (normalizar na query exigiria `regexp_replace()` sobre a coluna, o que
 *     descarta o índice B-tree e vira seq scan).
 *
 * A migration é idempotente: o UPDATE só toca linha que ainda tem caractere não numérico.
 *
 * Guardas de pré-voo, porque limpar máscara pode fundir duas linhas distintas em uma:
 *   1. Se a limpeza criaria colisão numa coluna com índice único, aborta com o diagnóstico —
 *      nunca perde dado em silêncio.
 *   2. Se algum valor ficaria truncado pelo novo tamanho, aborta.
 */
return new class extends Migration
{
    /**
     * Coluna → quantidade de dígitos do documento. Também é o novo tamanho do varchar.
     *
     * @var array<string, array{column: string, digits: int, unique: bool}>
     */
    private const DOCUMENT_COLUMNS = [
        'users' => ['column' => 'cpf', 'digits' => Cpf::DIGIT_COUNT, 'unique' => true],
        'professionals' => ['column' => 'cnpj', 'digits' => Cnpj::DIGIT_COUNT, 'unique' => true],
        'companies' => ['column' => 'cnpj', 'digits' => Cnpj::DIGIT_COUNT, 'unique' => false],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->normalizeUsersCnpj();

        foreach (self::DOCUMENT_COLUMNS as $table => $definition) {
            $this->guardAgainstDataLoss($table, $definition);
            $this->stripMaskInPlace($table, $definition['column']);
            $this->shrinkColumn($table, $definition['column'], $definition['digits']);
        }

        $this->createLookupIndexes();
    }

    /**
     * Irreversível de propósito: a máscara removida não pode ser reconstruída sem inventar
     * formatação, e o formato limpo é o contrato do sistema daqui em diante. O `down()`
     * apenas devolve a largura das colunas, para que um rollback não trave.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_companies_cnpj');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_users_cnpj');

        DB::statement('ALTER TABLE users ALTER COLUMN cpf TYPE varchar(14)');
        DB::statement('ALTER TABLE users ALTER COLUMN cnpj TYPE varchar(255)');
        DB::statement('ALTER TABLE professionals ALTER COLUMN cnpj TYPE varchar(18)');
        DB::statement('ALTER TABLE companies ALTER COLUMN cnpj TYPE varchar(255)');
    }

    /** `users.cnpj` não tem índice único, então entra fora do laço com as mesmas duas etapas. */
    private function normalizeUsersCnpj(): void
    {
        $this->guardAgainstTruncation('users', 'cnpj', Cnpj::DIGIT_COUNT);
        $this->stripMaskInPlace('users', 'cnpj');
        $this->shrinkColumn('users', 'cnpj', Cnpj::DIGIT_COUNT);
    }

    /**
     * @param  array{column: string, digits: int, unique: bool}  $definition
     */
    private function guardAgainstDataLoss(string $table, array $definition): void
    {
        $this->guardAgainstTruncation($table, $definition['column'], $definition['digits']);

        if ($definition['unique']) {
            $this->guardAgainstUniqueCollision($table, $definition['column']);
        }
    }

    private function guardAgainstTruncation(string $table, string $column, int $digits): void
    {
        $oversized = DB::scalar(
            "SELECT count(*) FROM {$table} WHERE {$column} IS NOT NULL AND length(regexp_replace({$column}, '[^0-9]', '', 'g')) > ?",
            [$digits]
        );

        if ((int) $oversized > 0) {
            throw new RuntimeException(
                "Abortado: {$oversized} linha(s) em {$table}.{$column} têm mais de {$digits} dígitos e seriam truncadas. Corrija os dados antes de rodar esta migration."
            );
        }
    }

    private function guardAgainstUniqueCollision(string $table, string $column): void
    {
        $collisions = DB::scalar(
            "SELECT count(*) FROM (
                SELECT regexp_replace({$column}, '[^0-9]', '', 'g') AS cleaned
                FROM {$table}
                WHERE {$column} IS NOT NULL
                GROUP BY 1
                HAVING count(*) > 1
            ) duplicates"
        );

        if ((int) $collisions > 0) {
            throw new RuntimeException(
                "Abortado: {$collisions} documento(s) em {$table}.{$column} viram duplicata depois de remover a máscara. Deduplique manualmente antes de rodar esta migration."
            );
        }
    }

    private function stripMaskInPlace(string $table, string $column): void
    {
        DB::statement(
            "UPDATE {$table} SET {$column} = regexp_replace({$column}, '[^0-9]', '', 'g') WHERE {$column} ~ '[^0-9]'"
        );
    }

    private function shrinkColumn(string $table, string $column, int $digits): void
    {
        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE varchar({$digits})");
    }

    /**
     * `users.cpf` e `professionals.cnpj` já têm índice único. As outras duas colunas de
     * documento não tinham índice nenhum — o lookup por CNPJ nelas era seq scan.
     * Parciais em `IS NOT NULL` porque documento nulo nunca é alvo de busca.
     */
    private function createLookupIndexes(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_users_cnpj ON users (cnpj) WHERE cnpj IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_companies_cnpj ON companies (cnpj) WHERE cnpj IS NOT NULL');
    }
};
