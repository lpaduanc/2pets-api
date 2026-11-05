<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Antecipação de recebíveis — docs/gap-petflow/PLANO.md, 2.1.
 *
 * - `receivable_anticipations`: uma antecipação confirmada (a simulação não persiste). `gross_total`
 *   = Σ líquido das parcelas antecipadas; `fee_total` = Σ taxa da antecipação; `net_total` = o que
 *   cai na conta. `financial_entry_id` = a despesa financeira da taxa.
 * - `receivable_anticipation_items`: por parcela, a taxa, os dias e a data ORIGINAL de depósito
 *   (o cancelamento devolve a parcela a ela). Uma tabela de itens, e não só
 *   `receivables.anticipation_id`, porque a parcela pode ser antecipada, cancelada e antecipada de
 *   novo: o histórico de cada antecipação (com a taxa e a data original de cada parcela) não pode
 *   ser sobrescrito pela seguinte.
 * - `receivables.anticipation_id` (coluna que nasceu solta na 1.1) ganha a FK: aponta a
 *   antecipação VIGENTE da parcela, para a agenda filtrar e mostrar sem juntar o histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_anticipations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('account_id')->constrained('financial_accounts');
            $table->date('anticipated_at');
            $table->string('mode', 20);
            $table->decimal('rate', 8, 4);
            $table->decimal('gross_total', 14, 2);
            $table->decimal('fee_total', 14, 2);
            $table->decimal('net_total', 14, 2);
            $table->string('status', 20);
            $table->foreignId('financial_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'anticipated_at']);
            $table->index(['professional_id', 'anticipated_at']);
        });

        Schema::create('receivable_anticipation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receivable_anticipation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('receivable_id')->constrained();
            $table->decimal('net_amount', 14, 2);
            $table->decimal('fee_amount', 14, 2);
            $table->unsignedInteger('days');
            $table->date('original_expected_date');
            $table->timestamps();

            $table->unique(['receivable_anticipation_id', 'receivable_id'], 'anticipation_receivable_unique');
            $table->index('receivable_id');
        });

        Schema::table('receivables', function (Blueprint $table): void {
            $table->foreign('anticipation_id')->references('id')->on('receivable_anticipations')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE receivable_anticipations ADD CONSTRAINT receivable_anticipations_mode_check CHECK (mode IN ('monthly_rate','fixed_rate'))");
            DB::statement("ALTER TABLE receivable_anticipations ADD CONSTRAINT receivable_anticipations_status_check CHECK (status IN ('simulated','confirmed','cancelled'))");
        }
    }

    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table): void {
            $table->dropForeign(['anticipation_id']);
        });

        Schema::dropIfExists('receivable_anticipation_items');
        Schema::dropIfExists('receivable_anticipations');
    }
};
