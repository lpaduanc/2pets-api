<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adendo a um prontuário finalizado — append-only, mesmo padrão de `consent_logs`.
 *
 * Exigência da Res. CFMV nº 1.321/2020 alt. 1.653/2025: correção de um registro finalizado
 * nunca reescreve o conteúdo original, sempre soma uma nova entrada datada e assinada
 * (`author_id`). Por isso não há `updated_at`, `deleted_at` nem rota de update/destroy —
 * ver docs/atendimento-veterinario/00-dominio-e-escopo.md §1.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_record_addenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained('medical_records')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['medical_record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_addenda');
    }
};
