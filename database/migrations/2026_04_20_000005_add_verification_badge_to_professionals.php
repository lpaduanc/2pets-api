<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md regra crítica §2: badge "verificado" só aparece após aprovação manual do CRMV.
 * Sem este campo, frontend não tinha fonte de verdade — ficava derivando de tabelas de documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->boolean('is_crmv_verified')->default(false)->after('crmv_state');
            $table->timestamp('crmv_verified_at')->nullable()->after('is_crmv_verified');
            $table->foreignId('crmv_verified_by')->nullable()->after('crmv_verified_at')->constrained('users')->nullOnDelete();

            $table->index('is_crmv_verified');
        });
    }

    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropForeign(['crmv_verified_by']);
            $table->dropIndex(['is_crmv_verified']);
            $table->dropColumn(['is_crmv_verified', 'crmv_verified_at', 'crmv_verified_by']);
        });
    }
};
