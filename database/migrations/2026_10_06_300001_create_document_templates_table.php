<?php

use App\Enums\DocumentTemplateKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modelo de documento (atestado, termo, declaração) — contrato docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md. `organization_id` nulo = catálogo
 * global (não editável via API, mesma regra dos demais catálogos desta leva).
 */
return new class extends Migration
{
    private const KIND_CONSTRAINT = 'document_templates_kind_check';

    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kind', 30);
            $table->text('body_html');
            $table->boolean('requires_signature')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'kind']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement($this->checkStatement('kind', self::KIND_CONSTRAINT, DocumentTemplateKind::values()));
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
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

        return 'ALTER TABLE document_templates ADD CONSTRAINT '.$constraint
            ." CHECK ({$column}::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
