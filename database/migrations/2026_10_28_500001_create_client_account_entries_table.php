<?php

use App\Enums\ClientAccountEntryDirection;
use App\Enums\ClientAccountEntryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extrato APPEND-ONLY da conta corrente do cliente — contrato
 * docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md. Mesma disciplina de
 * `cash_register_movements`: nunca `UPDATE`/`DELETE` em linha existente; correção é um
 * lançamento de ajuste novo (`adjustment_debit`/`adjustment_credit`). Por isso não tem
 * soft delete — não há "apagar" no vocabulário desta tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_account_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_client_account_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('direction', 10);
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->nullableMorphs('reference');
            $table->timestamp('occurred_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['company_client_account_id', 'occurred_at']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::getConnection()->statement(sprintf(
            "ALTER TABLE client_account_entries ADD CONSTRAINT client_account_entries_type_check CHECK (type IN ('%s'))",
            implode("','", ClientAccountEntryType::values())
        ));
        Schema::getConnection()->statement(sprintf(
            "ALTER TABLE client_account_entries ADD CONSTRAINT client_account_entries_direction_check CHECK (direction IN ('%s'))",
            implode("','", array_column(ClientAccountEntryDirection::cases(), 'value'))
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('client_account_entries');
    }
};
