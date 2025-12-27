<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->string('technical_responsible_name')->nullable()->after('technical_responsible_id');
            $table->string('technical_responsible_crmv')->nullable()->after('technical_responsible_name');
            $table->string('technical_responsible_crmv_state', 2)->nullable()->after('technical_responsible_crmv');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn([
                'technical_responsible_name',
                'technical_responsible_crmv',
                'technical_responsible_crmv_state'
            ]);
        });
    }
};
