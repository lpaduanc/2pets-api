<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `inventories` era a única lacuna apontada em `2026_09_13_000001_add_soft_deletes_to_medical_
 * records_invoices_services.php` ("mesma lacuna, mas é domínio de outro agente — não mexida
 * aqui"). Regra 5 do CLAUDE.md: soft delete em tudo — item de estoque removido continua
 * referenciável por `inventory_movements` e pelo histórico clínico que o debitou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
