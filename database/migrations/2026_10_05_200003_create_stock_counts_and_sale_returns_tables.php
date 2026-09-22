<?php

use App\Enums\RefundMethod;
use App\Enums\StockCountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/07 — inventário (contagem física) e devolução de venda.
 *
 * `stock_count_items.system_quantity` é a FOTO do saldo na abertura da contagem. O ajuste do
 * fechamento é `counted − system`, aplicado sobre o saldo daquele momento: venda feita
 * durante a contagem não é "corrigida" de volta pelo inventário.
 *
 * `sale_returns` guarda a devolução como documento próprio, ligada à venda de origem, em vez
 * de editar a venda: venda paga não se edita (`SaleNotEditableException`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('counted_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('responsible_id')->constrained('users');
            $table->foreignId('product_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default(StockCountStatus::OPEN->value);
            $table->unsignedInteger('items_correct')->default(0);
            $table->unsignedInteger('items_adjusted')->default(0);
            $table->decimal('adjustment_value', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['professional_id', 'status']);
        });

        Schema::create('stock_count_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('system_quantity');
            $table->integer('counted_quantity')->nullable();
            $table->integer('difference')->nullable();
            $table->boolean('adjusted')->default(false);
            $table->timestamps();

            $table->unique(['stock_count_id', 'product_id']);
        });

        Schema::create('sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->string('refund_method', 20)->default(RefundMethod::CASH->value);
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('user_id')->constrained();
            $table->timestamps();

            $table->index('sale_id');
            $table->index(['organization_id', 'created_at']);
            $table->index(['professional_id', 'created_at']);
        });

        Schema::create('sale_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('total', 14, 2);
            $table->timestamps();

            $table->index('sale_item_id');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();
        $connection->statement(sprintf(
            "ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_status_check CHECK (status IN ('%s'))",
            implode("','", StockCountStatus::values())
        ));
        $connection->statement(sprintf(
            "ALTER TABLE sale_returns ADD CONSTRAINT sale_returns_refund_method_check CHECK (refund_method IN ('%s'))",
            implode("','", RefundMethod::values())
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
    }
};
