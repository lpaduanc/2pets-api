<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('gateway'); // mercadopago, pagseguro
            $table->string('gateway_payment_id')->unique();
            $table->enum('method', ['pix', 'credit_card', 'debit_card', 'boleto']);
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['pending', 'processing', 'paid', 'failed', 'refunded', 'cancelled']);
            $table->integer('installments')->default(1);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('gateway_response')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['gateway', 'status']);
            $table->index('paid_at');
        });

        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('gateway');
            $table->string('event_type');
            $table->string('gateway_payment_id')->nullable();
            $table->json('payload');
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['gateway', 'processed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
        Schema::dropIfExists('payments');
    }
};

