<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * This migration replaces the MySQL-specific MODIFY COLUMN ENUM statements
 * from the original migrations (2025_12_06_213554 and 2025_12_07_215336).
 *
 * PostgreSQL handles enum columns created via Laravel's $table->enum() as
 * VARCHAR with CHECK constraints, so those work fine. The two problematic
 * migrations used raw MySQL ALTER TABLE ... MODIFY COLUMN ... ENUM syntax.
 *
 * Since we are starting fresh on PostgreSQL, this migration ensures the
 * `role` and `user_type` columns accept all the values the application needs.
 *
 * IMPORTANT: Run this migration AFTER a fresh `php artisan migrate` on
 * PostgreSQL. The original MySQL-only migrations (2025_12_06_213554 and
 * 2025_12_07_215336) should be deleted or skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Convert role to a plain VARCHAR — the application validates via
        // Form Requests / spatie-permission, so a CHECK is not needed.
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 30)->default('tutor')->change();
        });

        // Convert user_type to a plain VARCHAR as well
        if (Schema::hasColumn('users', 'user_type')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('user_type', 30)->nullable()->default('tutor')->change();
            });
        }
    }

    public function down(): void
    {
        // No-op: we don't revert back to MySQL ENUM syntax
    }
};
