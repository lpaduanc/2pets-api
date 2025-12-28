<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained('users')->onDelete('cascade');
            $table->string('campaign_type'); // featured_listing, banner, sponsored_post
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->string('target_url')->nullable();
            $table->decimal('daily_budget', 10, 2);
            $table->decimal('total_spent', 10, 2)->default(0);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('status', ['draft', 'active', 'paused', 'completed', 'cancelled'])->default('draft');
            $table->json('targeting')->nullable(); // location, specialty, etc.
            $table->timestamps();

            $table->index(['professional_id', 'status']);
            $table->index(['status', 'start_date', 'end_date']);
        });

        Schema::create('ad_impressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('ip_address', 45);
            $table->string('user_agent')->nullable();
            $table->timestamp('impressed_at');

            $table->index(['ad_campaign_id', 'impressed_at']);
        });

        Schema::create('ad_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained()->onDelete('cascade');
            $table->foreignId('ad_impression_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamp('clicked_at');

            $table->index(['ad_campaign_id', 'clicked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_clicks');
        Schema::dropIfExists('ad_impressions');
        Schema::dropIfExists('ad_campaigns');
    }
};

