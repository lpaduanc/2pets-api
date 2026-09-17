<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evolução diária da internação — contrato
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §1/§7.
 *
 * Append-only, mesmo padrão de `medical_record_addenda`: sem `updated_at`, sem `deleted_at`.
 * `recorded_at` é o momento CLÍNICO do fato (editável, pré-preenchido com "agora");
 * `created_at` é quando a entrada foi de fato digitada (nunca editável, `useCurrent()`).
 * Correção é uma entrada NOVA com `corrects_id` apontando para a original — a original
 * nunca desaparece nem é marcada como inválida (contrato §1.3).
 *
 * Exigência da Res. CFMV nº 1.321/2020 alt. 1.653/2025 (evolução diária com data, hora,
 * nome e CRMV do responsável) — `author_id` resolve nome+CRMV via `author->professional`,
 * sem coluna redundante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospitalization_progress_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospitalization_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('corrects_id')->nullable()->constrained('hospitalization_progress_notes')->nullOnDelete();

            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();

            $table->text('body');
            $table->decimal('temperature', 4, 1)->nullable();
            $table->integer('heart_rate')->nullable();
            $table->integer('respiratory_rate')->nullable();
            $table->decimal('weight', 5, 2)->nullable();

            $table->index(['hospitalization_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospitalization_progress_notes');
    }
};
