<?php

use App\Enums\QuoteStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/24-orcamentos.md — campos do orçamento em `sales`.
 *
 * Orçamento continua sendo `sales.kind = quote` (decisão do doc 24, já refletida em
 * `create_sales_tables`, que criou `valid_until` e `converted_to_sale_id`). Aqui entram só os
 * campos que a venda não tem: ciclo de aprovação, versão, origem clínica e o link público.
 *
 * `public_token_hash` guarda SHA-256 do token, nunca o token: quem lê o banco não consegue
 * aprovar orçamento de ninguém (mesmo desenho de `registration_continuation_tokens`).
 *
 * `root_quote_id` existe além de `parent_quote_id` porque a tela de comparação precisa de
 * TODAS as versões de uma vez; seguir a cadeia de `parent_quote_id` seria uma query por versão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('quote_status', 20)->nullable()->after('kind');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_channel', 20)->nullable();
            $table->ipAddress('decision_ip')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('parent_quote_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('root_quote_id')->nullable()->constrained('sales')->nullOnDelete();

            $table->foreignId('medical_record_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hospitalization_id')->nullable()->constrained()->nullOnDelete();

            $table->string('pdf_path')->nullable();
            $table->string('public_token_hash', 64)->nullable()->unique();
            $table->timestamp('public_token_used_at')->nullable();

            $table->index(['kind', 'quote_status', 'valid_until']);
            $table->index(['pet_id', 'kind']);
            $table->index('medical_record_id');
            $table->index('hospitalization_id');
            $table->index('root_quote_id');
        });

        // Orçamentos que já existiam (criados pelo PDV do doc 01) nascem como rascunho.
        DB::table('sales')->where('kind', 'quote')->whereNull('quote_status')
            ->update(['quote_status' => QuoteStatus::DRAFT->value]);

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $list = implode("','", QuoteStatus::values());
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_quote_status_check CHECK (quote_status IS NULL OR quote_status IN ('{$list}'))");
        // Venda nunca tem status de orçamento, e orçamento sempre tem.
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_quote_status_kind_check CHECK ((kind = 'quote') = (quote_status IS NOT NULL))");
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_decision_channel_check CHECK (decision_channel IS NULL OR decision_channel IN ('app','public_link'))");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_quote_status_check');
            DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_quote_status_kind_check');
            DB::statement('ALTER TABLE sales DROP CONSTRAINT IF EXISTS sales_decision_channel_check');
        }

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['kind', 'quote_status', 'valid_until']);
            $table->dropIndex(['pet_id', 'kind']);
            $table->dropIndex(['medical_record_id']);
            $table->dropIndex(['hospitalization_id']);
            $table->dropIndex(['root_quote_id']);
            $table->dropUnique(['public_token_hash']);

            $table->dropConstrainedForeignId('decided_by');
            $table->dropConstrainedForeignId('parent_quote_id');
            $table->dropConstrainedForeignId('root_quote_id');
            $table->dropConstrainedForeignId('medical_record_id');
            $table->dropConstrainedForeignId('hospitalization_id');

            $table->dropColumn([
                'quote_status', 'sent_at', 'viewed_at', 'decided_at', 'decision_channel',
                'decision_ip', 'rejection_reason', 'version', 'pdf_path',
                'public_token_hash', 'public_token_used_at',
            ]);
        });
    }
};
