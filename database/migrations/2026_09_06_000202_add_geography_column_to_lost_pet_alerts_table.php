<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6 do plano de otimizacao — substitui o Haversine cru de
 * `LostPetAlertService::getNearbyAlerts()`/`notifyNearbyUsers()` por PostGIS.
 *
 * A coluna nova se chama `last_seen_geo`, NAO `last_seen_location` (nome
 * usado no plano original de otimizacao): a tabela ja tem uma coluna real
 * `last_seen_location` do tipo varchar — o endereco textual digitado pelo
 * tutor (ex.: "Praca da Se, Sao Paulo"), criada pela migration original
 * (`2025_12_27_215000_create_lost_pet_alert_tables.php:17`) e usada pelo
 * `LostPetAlert::$fillable`. Reusar o nome sobrescreveria esse campo.
 * Divergencia reportada no relato da Fase 6.
 *
 * Indice GIST PARCIAL (`WHERE status = 'active'`): so alerta ativo entra no
 * predicado de `getNearbyAlerts()` e `notifyNearbyUsers()` — alertas
 * encontrados/cancelados nunca sao buscados por proximidade e nao precisam
 * ocupar espaco no indice. O btree comum antigo em
 * `(last_seen_latitude, last_seen_longitude)` ja foi dropado na Fase 4
 * (`2026_09_06_000006_drop_redundant_and_unused_indexes.php`) por nunca
 * servir busca por raio — este GIST parcial e o substituto real.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasColumn('lost_pet_alerts', 'last_seen_latitude')
            || ! Schema::hasColumn('lost_pet_alerts', 'last_seen_longitude')) {
            return;
        }

        DB::statement('ALTER TABLE lost_pet_alerts ADD COLUMN IF NOT EXISTS last_seen_geo geography(POINT, 4326)');

        DB::statement('
            UPDATE lost_pet_alerts
            SET last_seen_geo = ST_SetSRID(ST_MakePoint(last_seen_longitude, last_seen_latitude), 4326)::geography
            WHERE last_seen_latitude IS NOT NULL
              AND last_seen_longitude IS NOT NULL
              AND last_seen_geo IS NULL
        ');

        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_lost_pet_alerts_last_seen_geo_active
            ON lost_pet_alerts USING GIST (last_seen_geo)
            WHERE status = 'active'
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_lost_pet_alerts_last_seen_geo_active');

        if (Schema::hasColumn('lost_pet_alerts', 'last_seen_geo')) {
            DB::statement('ALTER TABLE lost_pet_alerts DROP COLUMN IF EXISTS last_seen_geo');
        }
    }
};
