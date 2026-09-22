<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 21 do backlog gap-simplesvet — "bloqueio geral com histórico visível". O dado
 * (soft delete) já existia; faltava só saber QUEM removeu, para `GET
 * professional/blocked-times/history` responder isso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blocked_times', function (Blueprint $table): void {
            $table->foreignId('deleted_by')->nullable()->after('reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('blocked_times', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
        });
    }
};
