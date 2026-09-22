<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil legal do profissional — contrato docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md. Colunas nullable aditivas.
 * `special_prescription_number`/certificado A1 NÃO entram agora (fora de escopo — RCEV/ICP
 * ainda não priorizados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->string('title', 30)->nullable()->after('crmv_state');
            $table->string('mapa_registration')->nullable()->after('title');
            $table->string('signature_image_path')->nullable()->after('mapa_registration');
        });
    }

    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table) {
            $table->dropColumn(['title', 'mapa_registration', 'signature_image_path']);
        });
    }
};
