<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // BookingService::createBooking writes status='pending' (awaiting confirmation by the professional).
        // The original CHECK constraint omitted 'pending', so the insert fails. Replace the constraint.
        //
        // `ALTER TABLE ... DROP CONSTRAINT` is PostgreSQL syntax; sqlite (used by the test suite,
        // see phpunit.xml) cannot alter a CHECK constraint at all. Skipping is safe there because
        // sqlite never enforced the original constraint either.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_status_check');
        DB::statement(
            'ALTER TABLE appointments ADD CONSTRAINT appointments_status_check '.
            "CHECK (status IN ('pending', 'scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_status_check');
        DB::statement(
            'ALTER TABLE appointments ADD CONSTRAINT appointments_status_check '.
            "CHECK (status IN ('scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'))"
        );
    }
};
