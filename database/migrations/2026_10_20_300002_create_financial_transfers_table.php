<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md — transferência entre
 * contas da própria clínica (banco ↔ operadora, banco ↔ banco). Nunca entra na DRE: não é
 * receita nem despesa, é dinheiro mudando de bolso da mesma empresa. Por isso não tem
 * `financial_category_id` nem `nature` — não existe categoria de transferência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->foreignId('from_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamp('occurred_at');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();

            $table->index(['organization_id', 'occurred_at']);
            $table->index(['professional_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transfers');
    }
};
