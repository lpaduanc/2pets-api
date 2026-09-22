<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. `organization_id` (nullable)
 * + `professional_id` no padrão `CommercialScopeResolver`, nunca `company_id` — mesmo motivo já
 * documentado nas migrations irmãs de 18/19/20/25.
 *
 * `consent_sms_marketing`/`consent_whatsapp_marketing` fecham o buraco de consentimento: já
 * existe `marketing_consent` (e-mail) e `consent_*_transactional` (default true) para os 3
 * canais — faltava só a versão "campanha" de SMS/WhatsApp, default `false` (opt-in).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('consent_sms_marketing')->default(false)->after('consent_sms_transactional');
            $table->boolean('consent_whatsapp_marketing')->default(false)->after('consent_whatsapp_transactional');
        });

        $this->createMessageTemplates();
        $this->createMessageAutomations();
        $this->createMessageCampaigns();
        $this->createMessageDispatches();
    }

    private function createMessageTemplates(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('channel', 20);
            $table->string('category', 20);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('whatsapp_template_name')->nullable();
            $table->json('placeholders')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id']);
            $table->index(['professional_id']);
        });
    }

    /**
     * `last_run_at` é o "já rodou hoje para este gatilho" no nível da automação — o dedup POR
     * cliente/pet é responsabilidade de `message_dispatches` (regra de negócio 3 da spec), não
     * desta coluna.
     */
    private function createMessageAutomations(): void
    {
        Schema::create('message_automations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('message_template_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 40);
            $table->integer('offset_days')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'active']);
            $table->index(['professional_id', 'active']);
            $table->index('trigger');
        });
    }

    private function createMessageCampaigns(): void
    {
        Schema::create('message_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('channel', 20);
            $table->foreignId('message_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_segment_id')->nullable()->constrained()->nullOnDelete();
            $table->json('ad_hoc_client_ids')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id']);
            $table->index(['professional_id']);
            $table->index('status');
        });
    }

    /**
     * Índice `(automation_id, client_id, pet_id, created_at)` — suporte direto ao dedup diário
     * da regra de negócio 3 (uma automação nunca dispara duas vezes no mesmo dia para o mesmo
     * par cliente/pet).
     */
    private function createMessageDispatches(): void
    {
        Schema::create('message_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20);
            $table->string('category', 20);
            $table->foreignId('template_id')->nullable()->constrained('message_templates')->nullOnDelete();
            $table->foreignId('automation_id')->nullable()->constrained('message_automations')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('message_campaigns')->nullOnDelete();
            $table->string('to_address')->nullable();
            $table->text('body_rendered');
            $table->string('status', 25);
            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->decimal('cost', 8, 4)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('failed_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['automation_id', 'client_id', 'pet_id', 'created_at'], 'message_dispatches_dedup_idx');
            $table->index(['organization_id']);
            $table->index(['professional_id']);
            $table->index(['client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_dispatches');
        Schema::dropIfExists('message_campaigns');
        Schema::dropIfExists('message_automations');
        Schema::dropIfExists('message_templates');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['consent_sms_marketing', 'consent_whatsapp_marketing']);
        });
    }
};
