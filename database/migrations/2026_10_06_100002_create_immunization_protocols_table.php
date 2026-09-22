<?php

use App\Enums\ImmunizationApplicationMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Um protocolo pertence a UM produto (`immunization_product_id`) — contrato spec 13.
 * `organization_id` nulo = protocolo padrão sugerido pela plataforma.
 */
return new class extends Migration
{
    private const MODE_CONSTRAINT = 'immunization_protocols_application_mode_check';

    public function up(): void
    {
        Schema::create('immunization_protocols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('immunization_product_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('application_mode', 20)->default(ImmunizationApplicationMode::INDEFINITE->value);
            $table->unsignedTinyInteger('total_doses')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('immunization_product_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement($this->checkStatement('application_mode', self::MODE_CONSTRAINT, ImmunizationApplicationMode::values()));
    }

    public function down(): void
    {
        Schema::dropIfExists('immunization_protocols');
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

        return 'ALTER TABLE immunization_protocols ADD CONSTRAINT '.$constraint
            ." CHECK ({$column}::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
