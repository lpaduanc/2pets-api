<?php

use App\Enums\FiscalEnvironment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transmissão própria de NF-e/NFC-e — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §3 e §4.
 *
 * `fiscal_certificates`: o A1 (.pfx) e a senha CIFRADOS (`FiscalSecretCipher`, chave própria e
 * contexto amarrado à organização) — nunca em disco público, nunca devolvidos por endpoint. Uma
 * linha por organização: trocar o certificado substitui a anterior (remoção física: o segredo
 * antigo não fica guardado; o log de atividade registra só os metadados).
 *
 * `fiscal_nfce_cscs`: CSC da NFC-e (id + token cifrado) por organização e ambiente — a SEFAZ
 * emite um CSC para homologação e outro para produção.
 *
 * Também: `organizations.city_ibge_code` (cMun do emitente, quando o nome da cidade não bate com
 * a tabela do IBGE), `fiscal_documents.cancellation_xml_path` (procEventoNFe do cancelamento) e
 * `fiscal_number_invalidations.xml_path` (procInutNFe) — documentos guardados por 5 anos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('pfx_ciphertext');
            $table->text('password_ciphertext');
            $table->string('subject_document', 14);
            $table->string('subject_name');
            $table->string('issuer_name')->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->char('fingerprint', 64);
            $table->string('cnpj_match', 10);
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fiscal_nfce_cscs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 15);
            $table->string('csc_id', 6);
            $table->text('token_ciphertext');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'environment']);
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->char('city_ibge_code', 7)->nullable();
        });

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->string('cancellation_xml_path')->nullable();
        });

        Schema::table('fiscal_number_invalidations', function (Blueprint $table): void {
            $table->string('xml_path')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $quoted = implode(', ', array_map(static fn (string $value): string => DB::getPdo()->quote($value), FiscalEnvironment::values()));
        DB::statement("ALTER TABLE fiscal_nfce_cscs ADD CONSTRAINT fiscal_nfce_cscs_environment_check CHECK (environment::text = ANY (ARRAY[{$quoted}]::text[]))");
        DB::statement("ALTER TABLE fiscal_certificates ADD CONSTRAINT fiscal_certificates_cnpj_match_check CHECK (cnpj_match IN ('exact', 'root'))");
    }

    public function down(): void
    {
        Schema::table('fiscal_number_invalidations', function (Blueprint $table): void {
            $table->dropColumn('xml_path');
        });

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropColumn('cancellation_xml_path');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('city_ibge_code');
        });

        Schema::dropIfExists('fiscal_nfce_cscs');
        Schema::dropIfExists('fiscal_certificates');
    }
};
