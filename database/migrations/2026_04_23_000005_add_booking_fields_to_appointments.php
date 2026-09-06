<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // BookingService stores a Carbon datetime; column must preserve the time.
            $table->dateTime('appointment_date')->change();

            // Tutor may book without picking a pet up-front (DTO allows null).
            $table->foreignId('pet_id')->nullable()->change();
        });

        // Columns below may already exist from partial prior attempts; add only if missing.
        if (! Schema::hasColumn('appointments', 'service_id')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->foreignId('service_id')->nullable()->after('pet_id')
                    ->constrained('services')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('appointments', 'booking_source')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->string('booking_source', 20)->nullable()->after('status');
            });
        }

        if (! Schema::hasColumn('appointments', 'requires_confirmation')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->boolean('requires_confirmation')->default(false)->after('booking_source');
            });
        }

        if (! Schema::hasColumn('appointments', 'confirmed_at')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->timestamp('confirmed_at')->nullable()->after('requires_confirmation');
            });
        }

        if (! Schema::hasColumn('appointments', 'cancelled_at')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->timestamp('cancelled_at')->nullable()->after('confirmed_at');
            });
        }

        if (! Schema::hasColumn('appointments', 'cancellation_reason')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (Schema::hasColumn('appointments', 'service_id')) {
                $table->dropForeign(['service_id']);
                $table->dropColumn('service_id');
            }
            foreach (['booking_source', 'requires_confirmation', 'confirmed_at', 'cancelled_at', 'cancellation_reason'] as $col) {
                if (Schema::hasColumn('appointments', $col)) {
                    $table->dropColumn($col);
                }
            }
            $table->date('appointment_date')->change();
            $table->foreignId('pet_id')->nullable(false)->change();
        });
    }
};
