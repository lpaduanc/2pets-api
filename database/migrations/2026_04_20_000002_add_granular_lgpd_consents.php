<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Granular consents — LGPD requires purpose-specific consent (Art. 8, §4).
            // Each flag maps to a distinct processing purpose the user can opt into or out of.
            $table->boolean('consent_search_visibility')->default(true)->after('data_sharing_consent');
            $table->boolean('consent_share_with_vets')->default(false)->after('consent_search_visibility');
            $table->boolean('consent_push_notifications')->default(true)->after('consent_share_with_vets');
            $table->boolean('consent_sms_transactional')->default(true)->after('consent_push_notifications');
            $table->boolean('consent_whatsapp_transactional')->default(true)->after('consent_sms_transactional');
            $table->boolean('consent_analytics')->default(false)->after('consent_whatsapp_transactional');
        });

        // Immutable audit log of every consent grant/revoke — required for ANPD incident response.
        Schema::create('consent_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('consent_key', 60);
            $table->boolean('granted');
            $table->string('source', 40)->default('self');  // self | admin | system
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['user_id', 'consent_key']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'consent_search_visibility',
                'consent_share_with_vets',
                'consent_push_notifications',
                'consent_sms_transactional',
                'consent_whatsapp_transactional',
                'consent_analytics',
            ]);
        });
    }
};
