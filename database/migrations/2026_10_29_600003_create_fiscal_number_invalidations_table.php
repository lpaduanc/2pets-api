<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inutilização de faixa de numeração — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md. Só relevante para emissão
 * direta com SEFAZ; a maioria dos provedores (Focus NFe, eNotas) abstrai isso. Tabela mantida
 * para o caso de o provedor escolhido expor o recurso — **não é crítica para o MVP**, ver
 * spec §Regras de negócio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_number_invalidations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('series');
            $table->unsignedInteger('number_from');
            $table->unsignedInteger('number_to');
            $table->string('reason');
            $table->string('protocol')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_number_invalidations');
    }
};
