<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §3.
 *
 * Linhas de cobrança lançadas durante o atendimento, uma por ato ("Vacina V10 — R$ 120,00"),
 * enquanto `medical_records.status = draft`. Depois de `finalize()` a tabela fica congelada —
 * a correção passa a viver na `Invoice`, nunca aqui.
 *
 * Sem `organization_id`: esta tabela é filha de `medical_records` (grupo CLÍNICO, autoria
 * sempre de uma pessoa física com CRMV — ver comentário de
 * `2026_09_14_100000_add_organization_id_to_commercial_tables.php`), não do grupo COMERCIAL.
 * Quem opera o faturamento por organização é resolvido via `medical_records.professional_id`
 * → vínculo de organização, nunca uma coluna própria aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_record_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->foreignId('added_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('medical_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_charges');
    }
};
