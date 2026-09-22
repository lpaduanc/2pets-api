<?php

use App\Enums\StockMovementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/07-estoque-movimentacoes-inventario-analise.md — livro de
 * movimentos do CATÁLOGO COMERCIAL (`products`).
 *
 * Até aqui `products.stock_quantity` era um número sem história: a venda decrementava direto
 * (`SaleService::deductStock`) e ninguém sabia responder "por que meu estoque está em 3?".
 * `stock_movements` é append-only — correção é um movimento novo, nunca a edição do antigo — e
 * `SUM(in) - SUM(out)` por produto tem que bater com `products.stock_quantity`
 * (`StockLedgerIntegrityTest`).
 *
 * Não se confunde com `inventory_movements`, que é o livro do INSUMO CLÍNICO (`inventories`,
 * vacina/vermífugo aplicados pelo vet). São dois estoques com donos diferentes; a baixa de
 * vacina aplicada (integração obrigatória do doc 07) já acontece lá, via
 * `ClinicalStockDeductionService`.
 *
 * Quantidade inteira, como `products.stock_quantity`: fracionar estoque (ração a granel) é
 * decisão de produto própria, e metade da tabela em decimal com a outra metade em inteiro
 * produziria saldo que não fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('batch_code', 60);
            $table->date('expires_at')->nullable();
            // Saldo DO LOTE. A soma dos lotes de um produto com `track_batches` bate com o
            // saldo do produto; o FEFO consome daqui.
            $table->integer('quantity')->default(0);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'batch_code']);
            $table->index(['product_id', 'expires_at']);
        });

        Schema::create('stock_exit_reasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            // Saída que é PERDA (quebra, vencimento) entra no custo da operação no DRE (doc 02);
            // amostra/brinde não. O relatório lê esta flag, não o nome.
            $table->boolean('affects_cost')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active']);
            $table->index(['professional_id', 'active']);
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            // Dono espelhado do produto NO MOMENTO do movimento — mesma regra de
            // `inventory_movements`.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();

            $table->string('type', 30);
            $table->string('direction', 3);
            // Sempre positiva; o sentido é `direction`.
            $table->integer('quantity');
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->integer('balance_after');

            $table->foreignId('reason_id')->nullable()->constrained('stock_exit_reasons')->nullOnDelete();
            $table->string('reference_type', 120)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamp('occurred_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'occurred_at']);
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['professional_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $connection = Schema::getConnection();
            $connection->statement(sprintf(
                "ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN ('%s'))",
                implode("','", StockMovementType::values())
            ));
            $connection->statement(
                "ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_direction_check CHECK (direction IN ('in','out'))"
            );
            $connection->statement(
                'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_check CHECK (quantity > 0)'
            );
            $connection->statement(
                'CREATE UNIQUE INDEX stock_exit_reasons_org_name_unique ON stock_exit_reasons (organization_id, lower(name)) WHERE deleted_at IS NULL AND organization_id IS NOT NULL'
            );
            $connection->statement(
                'CREATE UNIQUE INDEX stock_exit_reasons_professional_name_unique ON stock_exit_reasons (professional_id, lower(name)) WHERE deleted_at IS NULL AND organization_id IS NULL'
            );
        }

        $this->backfillOpeningBalances();
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_exit_reasons');
        Schema::dropIfExists('product_batches');
    }

    /**
     * Saldo que já existia antes do livro vira um movimento `opening_balance` — sem ele, o
     * primeiro produto com estoque herdado quebraria a invariante `SUM(movimentos) = saldo`.
     * Saldo negativo (venda sem estoque, permitido no balcão) entra como saída pelo mesmo
     * motivo.
     */
    private function backfillOpeningBalances(): void
    {
        $now = now();

        DB::table('products')
            ->where('stock_quantity', '!=', 0)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(500, function ($products) use ($now): void {
                $rows = [];

                foreach ($products as $product) {
                    $quantity = (int) $product->stock_quantity;
                    $cost = (float) ($product->average_cost ?? 0);

                    $rows[] = [
                        'organization_id' => $product->organization_id,
                        'professional_id' => $product->professional_id,
                        'product_id' => $product->id,
                        'type' => StockMovementType::OPENING_BALANCE->value,
                        'direction' => $quantity > 0 ? 'in' : 'out',
                        'quantity' => abs($quantity),
                        'unit_cost' => $cost,
                        'total_cost' => round(abs($quantity) * $cost, 2),
                        'balance_after' => $quantity,
                        'occurred_at' => $now,
                        'notes' => 'Saldo existente antes do livro de movimentos.',
                        'created_at' => $now,
                    ];
                }

                DB::table('stock_movements')->insert($rows);
            });
    }
};
