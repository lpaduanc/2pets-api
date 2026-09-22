<?php

use App\Enums\PetImmunizationPlanStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Um pet "está em" um protocolo desde uma data — contrato spec 13. */
return new class extends Migration
{
    private const STATUS_CONSTRAINT = 'pet_immunization_plans_status_check';

    public function up(): void
    {
        Schema::create('pet_immunization_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->restrictOnDelete();
            $table->foreignId('protocol_id')->constrained('immunization_protocols')->restrictOnDelete();
            $table->date('started_at');
            $table->string('status', 20)->default(PetImmunizationPlanStatus::ACTIVE->value);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['pet_id', 'status']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement($this->checkStatement('status', self::STATUS_CONSTRAINT, PetImmunizationPlanStatus::values()));
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_immunization_plans');
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

        return 'ALTER TABLE pet_immunization_plans ADD CONSTRAINT '.$constraint
            ." CHECK ({$column}::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
