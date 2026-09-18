<?php

use App\Enums\CashMovementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/01-caixa-pdv.md — "Suprimento e movimentos manuais".
 *
 * Livro-razão APPEND-ONLY do caixa, mesma disciplina de `inventory_movements`
 * (docs/vinculo-estoque-aplicacao-clinica.md item 6): nunca `update`, nunca `delete`. Lançamento
 * errado se corrige com um movimento de ajuste, nunca editando o antigo — senão a diferença do
 * fechamento pode ser "arrumada" depois do fato e a conferência perde a serventia.
 *
 * `amount` é SEMPRE positivo; a direção vem de `type` (`CashMovementType::signFor()`). Ver o
 * enum para o porquê.
 *
 * `reference_type`/`reference_id` polimórficos apontam para a venda, o recebimento ou o
 * lançamento que originou o movimento — é o que permite ir do valor na gaveta até o documento.
 *
 * Criada DEPOIS de `sales` porque `cash_registers` já existe e o movimento pode referenciar
 * venda; a ordem das migrations respeita a dependência de FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_register_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20);
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();

            $table->decimal('amount', 14, 2);
            $table->timestamp('occurred_at');
            $table->string('description');

            $table->foreignId('user_id')->constrained('users');

            $table->nullableMorphs('reference');

            $table->timestamps();

            $table->index(['cash_register_id', 'occurred_at']);
            $table->index(['cash_register_id', 'payment_method_id']);
            $table->index(['account_id', 'occurred_at']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();
        $types = implode("','", CashMovementType::values());

        $connection->statement(
            "ALTER TABLE cash_register_movements ADD CONSTRAINT cash_register_movements_type_check CHECK (type IN ('{$types}'))"
        );

        // Valor sempre positivo: a trava que torna `signFor()` a ÚNICA fonte de direção.
        $connection->statement(
            'ALTER TABLE cash_register_movements ADD CONSTRAINT cash_register_movements_amount_positive CHECK (amount > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_movements');
    }
};
