<?php

use App\Enums\CashRegisterStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/01-caixa-pdv.md — "Caixa".
 *
 * Um caixa é uma SESSÃO de trabalho (abriu de manhã, fechou à noite), não um posto físico. Por
 * isso `opened_by` + `opened_at` e não um cadastro de "caixa 1 / caixa 2": o SimplesVet mostra
 * "Meus caixas" e "Outros caixas" porque cada operador tem a própria sessão aberta ao mesmo
 * tempo, e cada um responde pela própria gaveta.
 *
 * Critério de aceite: "não consegue abrir dois caixas simultâneos para si" — garantido pelo
 * índice parcial único no fim, não só pela checagem de aplicação. Dois cliques rápidos no
 * botão "Abrir caixa" passam pela validação duas vezes; pelo índice, não.
 *
 * `counted_amount` (o que o operador contou) fica separado de `closing_amount` (o que o sistema
 * esperava) de propósito: a `difference` entre os dois é a informação que o encerramento
 * examina. Gravar só o resultado apagaria a evidência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('name')->nullable();

            $table->foreignId('opened_by')->constrained('users');
            $table->timestamp('opened_at');
            $table->decimal('opening_amount', 14, 2)->default(0);

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('closing_amount', 14, 2)->nullable();
            $table->decimal('counted_amount', 14, 2)->nullable();
            $table->decimal('difference', 14, 2)->nullable();

            // Conferência cega por forma de pagamento: {payment_method_id: {expected, counted}}.
            // JSON e não tabela filha porque a conferência é um SNAPSHOT do fechamento — nunca
            // é consultada fora do próprio caixa nem agregada entre caixas.
            $table->json('closing_breakdown')->nullable();

            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();

            $table->string('status', 20)->default(CashRegisterStatus::OPEN->value);
            $table->text('notes')->nullable();
            $table->text('review_reason')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['opened_by', 'status']);
            $table->index('opened_at');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();

        $connection->statement(sprintf(
            "ALTER TABLE cash_registers ADD CONSTRAINT cash_registers_status_check CHECK (status IN ('%s'))",
            implode("','", CashRegisterStatus::values())
        ));

        // A trava real do "um caixa aberto por pessoa" (ver nota da classe).
        $connection->statement(
            "CREATE UNIQUE INDEX cash_registers_one_open_per_user ON cash_registers (opened_by) WHERE status = 'open'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};
