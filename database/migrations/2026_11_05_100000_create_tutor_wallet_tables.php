<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carteira do tutor: gastos que ele mesmo registra (`tutor_expenses`) e gastos que se repetem
 * sozinhos (`tutor_recurring_expenses`).
 *
 * O que o tutor paga PELO 2pets (faturas pagas) não é copiado para cá: o resumo lê direto de
 * `invoices` (ver `WalletSummaryService`). Copiar exigiria manter as duas tabelas em
 * sincronia a cada estorno ou cancelamento; ler na hora nunca fica desatualizado.
 *
 * `category`/`subcategory`/`frequency`/`payment_method` são `varchar` validados pelos enums de
 * `App\Enums\Wallet`, sem CHECK no banco: o catálogo vai crescer com o uso, e acrescentar uma
 * subcategoria não deve exigir migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 32);
            $table->string('subcategory', 32)->nullable();
            $table->string('description', 120)->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('payment_method', 32)->nullable();
            $table->string('frequency', 16);
            // Próxima data em que o gasto será lançado. Avança a cada lançamento.
            $table->date('next_due_on');
            $table->boolean('active')->default(true);
            $table->timestamps();

            // O comando diário só olha recorrências ativas e vencidas.
            $table->index(['active', 'next_due_on']);
        });

        Schema::create('tutor_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Nulo = gasto de todos os pets (ex.: um saco de ração para os dois cachorros).
            $table->foreignId('pet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 32);
            $table->string('subcategory', 32)->nullable();
            // Opcional: sem descrição, o app mostra o rótulo da subcategoria.
            $table->string('description', 120)->nullable();
            $table->decimal('amount', 10, 2);
            $table->date('spent_on');
            $table->string('payment_method', 32)->nullable();
            $table->foreignId('recurring_expense_id')->nullable()
                ->constrained('tutor_recurring_expenses')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'spent_on']);
            // Garante que o comando diário nunca lance a mesma recorrência duas vezes no mesmo
            // dia, mesmo que rode em dois servidores ou seja reexecutado à mão.
            $table->unique(['recurring_expense_id', 'spent_on']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tutor_expenses ADD CONSTRAINT tutor_expenses_amount_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE tutor_recurring_expenses ADD CONSTRAINT tutor_recurring_expenses_amount_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_expenses');
        Schema::dropIfExists('tutor_recurring_expenses');
    }
};
