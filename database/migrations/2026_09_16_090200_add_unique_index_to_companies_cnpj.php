<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `companies.cnpj` nunca teve constraint de unicidade nenhuma — a migration que criou o
 * índice (`2026_09_06_150000_normalize_document_columns`) marcou `'unique' => false` de
 * propósito, deixando só um índice não-único (`idx_companies_cnpj`) para acelerar o lookup.
 * Duas empresas parceiras podiam se cadastrar com o mesmo CNPJ sem erro nenhum.
 *
 * `Company` não usa `SoftDeletes` (não foi adicionado nesta onda — fora do escopo pedido),
 * então o índice único aqui é PLANO, sem `WHERE deleted_at IS NULL` — não haveria coluna
 * para filtrar.
 *
 * Guarda de pré-voo: aborta com diagnóstico se já existir duplicata em produção, em vez de
 * falhar com um erro de banco sem contexto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->guardAgainstExistingDuplicates();

        DB::statement('DROP INDEX IF EXISTS idx_companies_cnpj');
        DB::statement('CREATE UNIQUE INDEX companies_cnpj_unique ON companies (cnpj) WHERE cnpj IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS companies_cnpj_unique');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_companies_cnpj ON companies (cnpj) WHERE cnpj IS NOT NULL');
    }

    private function guardAgainstExistingDuplicates(): void
    {
        $duplicates = DB::scalar(
            'SELECT count(*) FROM (
                SELECT cnpj FROM companies WHERE cnpj IS NOT NULL GROUP BY cnpj HAVING count(*) > 1
            ) duplicates'
        );

        if ((int) $duplicates > 0) {
            throw new RuntimeException(
                "Abortado: {$duplicates} CNPJ(s) duplicado(s) em companies.cnpj. Deduplique manualmente antes de rodar esta migration."
            );
        }
    }
};
