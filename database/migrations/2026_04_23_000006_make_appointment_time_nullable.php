<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // appointment_date now carries both date and time. appointment_time stays for legacy
        // reads from the professional-side scheduler, but booking flow no longer writes it.
        Schema::table('appointments', function (Blueprint $table) {
            $table->time('appointment_time')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->time('appointment_time')->nullable(false)->change();
        });
    }
};
