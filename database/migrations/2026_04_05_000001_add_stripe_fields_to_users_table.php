<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Adiciona campos do Stripe na tabela users para vincular
     * o customer e a assinatura ativa do usuario.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->after('is_suspended');
            $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id');

            $table->index('stripe_customer_id');
            $table->index('stripe_subscription_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['stripe_subscription_id']);
            $table->dropIndex(['stripe_customer_id']);

            $table->dropColumn([
                'stripe_customer_id',
                'stripe_subscription_id',
            ]);
        });
    }
};
