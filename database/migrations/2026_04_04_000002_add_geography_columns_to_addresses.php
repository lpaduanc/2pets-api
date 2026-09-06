<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add PostGIS geography column and spatial index to the users table
     * (which currently holds address/lat/lng directly).
     *
     * Also creates a trigram GIN index on professional business_name
     * for fuzzy search.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // Migration é PostGIS-only; no SQLite dos testes ela vira no-op.
        }

        // Add geography(POINT, 4326) column to users for spatial queries
        if (Schema::hasColumn('users', 'latitude') && Schema::hasColumn('users', 'longitude')) {
            DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS location geography(POINT, 4326)');

            // Back-fill existing rows that already have lat/lng
            DB::statement('
                UPDATE users
                SET location = ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)::geography
                WHERE latitude IS NOT NULL
                  AND longitude IS NOT NULL
                  AND location IS NULL
            ');

            // Spatial index
            DB::statement('CREATE INDEX IF NOT EXISTS idx_users_location ON users USING GIST (location)');
        }

        // Trigram index on professional business_name for fuzzy search
        if (Schema::hasTable('professionals') && Schema::hasColumn('professionals', 'business_name')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_professionals_business_name_trgm ON professionals USING GIN (business_name gin_trgm_ops)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_professionals_business_name_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_users_location');

        if (Schema::hasColumn('users', 'location')) {
            DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS location');
        }
    }
};
