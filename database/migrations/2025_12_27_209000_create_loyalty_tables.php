<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->unique();
            $table->integer('points_balance')->default(0);
            $table->enum('tier', ['bronze', 'silver', 'gold', 'platinum'])->default('bronze');
            $table->integer('lifetime_points')->default(0);
            $table->date('tier_expires_at')->nullable();
            $table->timestamps();

            $table->index('tier');
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_account_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['earned', 'redeemed', 'expired', 'bonus', 'refunded']);
            $table->integer('points');
            $table->string('description');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();

            $table->index(['loyalty_account_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->integer('points_cost');
            $table->string('reward_type'); // discount, free_service, product, voucher
            $table->json('reward_data');
            $table->integer('stock')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('reward_id')->constrained();
            $table->integer('points_spent');
            $table->enum('status', ['pending', 'completed', 'cancelled', 'expired'])->default('pending');
            $table->string('redemption_code')->unique();
            $table->date('expires_at')->nullable();
            $table->date('used_at')->nullable();
            $table->timestamps();

            $table->index(['loyalty_account_id', 'status']);
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('referred_id')->constrained('users')->onDelete('cascade');
            $table->string('referral_code')->unique();
            $table->enum('status', ['pending', 'completed', 'rewarded'])->default('pending');
            $table->integer('points_awarded')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('referral_code');
            $table->index(['referrer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('reward_redemptions');
        Schema::dropIfExists('rewards');
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_accounts');
    }
};

