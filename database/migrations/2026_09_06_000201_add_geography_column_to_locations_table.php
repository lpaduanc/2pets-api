<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6 do plano de otimizacao — substitui o Haversine cru de
 * `LocationService::getNearbyLocations()` (selectRaw + HAVING sobre
 * `latitude`/`longitude`) por PostGIS. Sem coluna `geography` nao ha indice
 * GIST possivel; `HAVING distance <= ?` avaliava a expressao trigonometrica
 * para TODA linha da tabela, sempre, porque e calculada apos a projecao.
 *
 * `CONCURRENTLY` nao pode rodar dentro de uma transacao — daih o
 * `$withinTransaction = false` e o padrao ja usado nas migrations de indice
 * da Fase 4 (`2026_09_06_000001_...`).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasColumn('locations', 'latitude') || ! Schema::hasColumn('locations', 'longitude')) {
            return;
        }

        DB::statement('ALTER TABLE locations ADD COLUMN IF NOT EXISTS location geography(POINT, 4326)');

        DB::statement('
            UPDATE locations
            SET location = ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)::geography
            WHERE latitude IS NOT NULL
              AND longitude IS NOT NULL
              AND location IS NULL
        ');

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_locations_location ON locations USING GIST (location)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_locations_location');

        if (Schema::hasColumn('locations', 'location')) {
            DB::statement('ALTER TABLE locations DROP COLUMN IF EXISTS location');
        }
    }
};
