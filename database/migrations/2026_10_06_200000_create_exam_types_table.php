<?php

use App\Enums\ExamTypeCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de exame da clínica — contrato docs/gap-simplesvet/specs/16-modelos-exame-laudos-spec.md.
 * `organization_id` nulo = catálogo global (não editável via API, mesma regra dos demais
 * catálogos desta leva — `OrganizationCatalogGate`). `service_id` (nullable) é o que é
 * cobrado — aponta para `services` (catálogo vendável já existente), não para `products`
 * (catálogo comercial de balcão, conceito diferente neste domínio).
 */
return new class extends Migration
{
    private const CATEGORY_CONSTRAINT = 'exam_types_category_check';

    public function up(): void
    {
        Schema::create('exam_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('category', 20);
            $table->text('presentation_html')->nullable();
            $table->text('closing_html')->nullable();
            $table->text('preparation_instructions')->nullable();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->unsignedSmallInteger('default_duration_minutes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'category']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement($this->checkStatement('category', self::CATEGORY_CONSTRAINT, ExamTypeCategory::values()));
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_types');
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

        return 'ALTER TABLE exam_types ADD CONSTRAINT '.$constraint
            ." CHECK ({$column}::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
