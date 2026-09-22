<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Override de sinal POR SERVIÇO (Fase 6) — resolução: serviço (se tiver override) →
 * estabelecimento → desligado (`DepositConfigResolver`).
 *
 * `deposit_enabled` aqui é NULLABLE (tri-state), diferente do booleano simples de
 * `organizations`/`professionals`: `NULL` = "sem override, herda do estabelecimento";
 * `true`/`false` = override explícito (inclusive para DESLIGAR o sinal só para este
 * serviço, mesmo com o estabelecimento ligado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->boolean('deposit_enabled')->nullable()->after('allow_price_override');
            $table->decimal('deposit_percentage', 5, 2)->nullable()->after('deposit_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn(['deposit_enabled', 'deposit_percentage']);
        });
    }
};
