<?php

use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documento fiscal emitido para uma venda — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md. Uma venda mista (produto +
 * serviço) gera DOIS registros aqui (um `nfce`/`nfe`, um `nfse`) — nunca um documento só.
 *
 * `provider = 'log'` é o driver padrão (`LogFiscalProviderGateway`); `provider_id` é o
 * identificador do provedor real quando um for configurado. `xml_path`/`pdf_path` guardam
 * caminho no storage, nunca o conteúdo binário na própria linha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('series')->nullable();
            $table->string('number')->nullable();
            $table->string('access_key')->nullable();
            $table->string('status', 15)->default(FiscalDocumentStatus::PENDING->value);
            $table->string('provider')->default('log');
            $table->string('provider_id')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->decimal('total', 12, 2);
            $table->json('tax_breakdown')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['professional_id', 'status']);
            $table->index('sale_id');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::getConnection()->statement(sprintf(
            "ALTER TABLE fiscal_documents ADD CONSTRAINT fiscal_documents_kind_check CHECK (kind IN ('%s'))",
            implode("','", array_column(FiscalDocumentKind::cases(), 'value'))
        ));
        Schema::getConnection()->statement(sprintf(
            "ALTER TABLE fiscal_documents ADD CONSTRAINT fiscal_documents_status_check CHECK (status IN ('%s'))",
            implode("','", FiscalDocumentStatus::values())
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};
