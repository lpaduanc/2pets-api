<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add missing indexes to pets table for query performance.
 *
 * PostgreSQL does NOT auto-create indexes on FK columns.
 * The user_id column is used in every pet listing query (WHERE user_id = ?)
 * and must be indexed for sub-second response times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            // Primary query filter -- every pet listing filters by user_id
            $table->index('user_id', 'idx_pets_user_id');

            // Species filter (common in search/filter)
            $table->index('species', 'idx_pets_species');

            // Public ID lookups (pet card, sharing)
            $table->unique('public_id', 'idx_pets_public_id_unique');

            // Lost pets query
            $table->index('is_lost', 'idx_pets_is_lost');
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropIndex('idx_pets_user_id');
            $table->dropIndex('idx_pets_species');
            $table->dropUnique('idx_pets_public_id_unique');
            $table->dropIndex('idx_pets_is_lost');
        });
    }
};
