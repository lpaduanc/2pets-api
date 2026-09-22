<?php

use App\Enums\GeneratedDocumentSignatureType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documento gerado — contrato docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md, regra de negócio 3: `body_html` é
 * cópia CONGELADA do template no momento da emissão. `organization_id` espelha o dono no
 * momento da emissão (mesmo padrão já usado em `inventory_movements.organization_id`).
 */
return new class extends Migration
{
    private const SIGNATURE_TYPE_CONSTRAINT = 'generated_documents_signature_type_check';

    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('pet_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('medical_record_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->text('body_html');
            $table->string('pdf_path')->nullable();
            $table->string('signature_type', 20)->default(GeneratedDocumentSignatureType::NONE->value);
            $table->string('signature_ref')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('hash')->nullable();
            $table->string('verification_code')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index('pet_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement($this->checkStatement(
            'signature_type',
            self::SIGNATURE_TYPE_CONSTRAINT,
            GeneratedDocumentSignatureType::values()
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }

    /**
     * @param  list<string>  $values
     */
    private function checkStatement(string $column, string $constraint, array $values): string
    {
        $quoted = implode(', ', array_map(
            static fn (string $value): string => DB::getPdo()->quote($value),
            $values,
        ));

        return 'ALTER TABLE generated_documents ADD CONSTRAINT '.$constraint
            ." CHECK ({$column}::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
