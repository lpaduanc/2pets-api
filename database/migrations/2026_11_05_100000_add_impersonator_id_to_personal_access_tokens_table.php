<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impersonação do 2pets Admin: o token Sanctum emitido para o tutor/profissional carrega quem
 * da equipe abriu a sessão. É a única fonte de verdade de "esta sessão é do suporte" — o
 * frontend só reflete o que `GET /user` devolve (ver `ImpersonationService`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('impersonator_id')->nullable()->after('tokenable_id')
                ->constrained('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonator_id');
        });
    }
};
