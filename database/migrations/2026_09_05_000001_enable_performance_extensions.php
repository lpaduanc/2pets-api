<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Enable the PostgreSQL extensions used by the Fase 0 performance instrumentation.
     *
     * pg_stat_statements - per-query aggregate stats (calls, total_exec_time, rows) used by
     *                      storage/perf/capture.ps1 to rank the hottest queries. Requires
     *                      `shared_preload_libraries=pg_stat_statements` in postgresql.conf;
     *                      without it, CREATE EXTENSION fails with a clear Postgres error.
     *                      That failure is tolerated (try/catch + log) instead of aborting
     *                      the migration, because this file may run against a container that
     *                      hasn't been recreated with the new `command:` yet.
     * btree_gin          - lets a GIN index cover plain scalar columns; needed by the Fase 4
     *                      composite indexes that combine trigram/geo expressions with
     *                      scalar filters.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->createExtensionSafely('btree_gin');
        $this->createExtensionSafely('pg_stat_statements');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP EXTENSION IF EXISTS btree_gin');
        DB::statement('DROP EXTENSION IF EXISTS pg_stat_statements');
    }

    private function createExtensionSafely(string $extension): void
    {
        try {
            DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
        } catch (\Throwable $exception) {
            Log::channel('perf')->warning("Não foi possível criar a extensão Postgres '{$extension}'.", [
                'extension' => $extension,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
};
