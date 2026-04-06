<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * PostgreSQL-compatible: user_type is VARCHAR, no ENUM constraint to modify.
     */
    public function up(): void
    {
        // In PostgreSQL, string columns accept any value.
        // The 'company' value is already valid for a VARCHAR column.
        // This migration is kept for compatibility with the migration history.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op for PostgreSQL
    }
};
