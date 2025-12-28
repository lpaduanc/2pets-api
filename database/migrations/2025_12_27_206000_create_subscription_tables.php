<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('tier', ['basic', 'pro', 'enterprise']);
            $table->decimal('monthly_price', 10, 2);
            $table->decimal('yearly_price', 10, 2)->nullable();
            $table->integer('trial_days')->default(0);
            $table->json('features');
            $table->json('limits');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_plan_id')->constrained();
            $table->enum('status', ['active', 'trialing', 'past_due', 'cancelled', 'expired'])->default('trialing');
            $table->enum('billing_cycle', ['monthly', 'yearly']);
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->date('trial_ends_at')->nullable();
            $table->date('cancelled_at')->nullable();
            $table->string('gateway_subscription_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('subscription_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->onDelete('cascade');
            $table->string('feature');
            $table->integer('used')->default(0);
            $table->integer('limit');
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamps();

            $table->index(['subscription_id', 'feature', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_usage');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};

