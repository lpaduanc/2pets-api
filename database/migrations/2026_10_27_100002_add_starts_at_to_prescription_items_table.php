<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Próxima dose" derivada em internação — contrato docs/gap-simplesvet/specs/
 * 12-internacao-mapa-execucao-spec.md §3. Nullable e irrelevante para receita de alta comum
 * (só usado quando o item pendura numa prescrição de internação) — coluna aditiva, sem
 * migração de dado histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_items', function (Blueprint $table): void {
            $table->timestamp('starts_at')->nullable()->after('is_continuous_use');
        });
    }

    public function down(): void
    {
        Schema::table('prescription_items', function (Blueprint $table): void {
            $table->dropColumn('starts_at');
        });
    }
};
