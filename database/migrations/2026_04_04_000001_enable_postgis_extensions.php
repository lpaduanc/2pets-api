<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enable PostgreSQL extensions required by 2pets.
     *
     * PostGIS  - spatial queries (ST_DWithin, ST_Distance, geography type)
     * pg_trgm  - fuzzy / trigram search (similarity(), GIN indexes)
     * unaccent - accent-insensitive search for Portuguese text
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // SQLite/MySQL test envs não têm extensões pg.
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP EXTENSION IF EXISTS unaccent');
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
        DB::statement('DROP EXTENSION IF EXISTS postgis');
    }
};
