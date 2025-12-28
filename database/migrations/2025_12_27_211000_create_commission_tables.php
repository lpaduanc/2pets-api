<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained('users')->onDelete('cascade');
            $table->decimal('total_amount', 10, 2);
            $table->integer('commission_count');
            $table->enum('status', ['pending', 'processing', 'paid', 'failed'])->default('pending');
            $table->string('payment_method'); // pix, bank_transfer
            $table->json('payment_details')->nullable(); // PIX key, bank account
            $table->string('payment_reference')->nullable();
            $table->date('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['professional_id', 'status']);
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained('users')->onDelete('cascade');
            $table->string('transaction_type'); // appointment, product_sale, service
            $table->unsignedBigInteger('transaction_id');
            $table->decimal('transaction_amount', 10, 2);
            $table->decimal('commission_rate', 5, 2); // Percentage
            $table->decimal('commission_amount', 10, 2);
            $table->enum('status', ['pending', 'approved', 'paid', 'cancelled'])->default('pending');
            $table->foreignId('payout_id')->nullable()->constrained();
            $table->timestamps();

            $table->index(['professional_id', 'status']);
            $table->index(['transaction_type', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('payouts');
    }
};

