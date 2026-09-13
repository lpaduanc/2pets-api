<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `Professional` nunca teve `deleted_at` — o único jeito de "remover" um registro em
 * colisão de CNPJ era `->delete()` físico (ver `RegistrationDraftController`, corrigido na
 * mesma onda). Regra 5 do CLAUDE.md: soft delete em tudo, nunca `DELETE`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            if (! Schema::hasColumn('professionals', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            if (Schema::hasColumn('professionals', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
