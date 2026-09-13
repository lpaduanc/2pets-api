<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Livro-razão append-only de toda mudança em `inventories.quantity` — docs/vinculo-estoque-
 * aplicacao-clinica.md item 6. `Inventory` não usava `LogsActivity`, então até aqui uma baixa de
 * estoque não deixava rastro nenhum, nem genérico; o CLAUDE.md já exige "tabela de audit logs
 * para todas as operações sensíveis", e movimentação de estoque com implicação financeira é
 * operação sensível por definição.
 *
 * `organization_id` espelha o dono do item NO MOMENTO do movimento — nunca recalculado depois,
 * por isso não tem `constrained()` (seria um FK redundante e, se a organização mudar de dono no
 * futuro, o histórico teria que continuar apontando para o valor antigo, não para o atual).
 * `reference_type`/`reference_id` são um morph manual (não `morphs()`) para não herdar o índice
 * composto automático numa tabela que já tem `inventory_id` como filtro primário mais comum.
 *
 * Sem `updated_at`: é um livro-razão, não um registro editável (`$timestamps = false` no
 * model, `created_at` com `useCurrent()` aqui — mesmo padrão de `consent_logs`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->integer('quantity_delta');
            $table->string('reference_type', 120)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['inventory_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
