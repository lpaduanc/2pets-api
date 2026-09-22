<?php

use App\Enums\ImmunizationDoseAnchor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grafo de doses de um protocolo — contrato spec 13, regras 3/4. `depends_on_dose_id` é
 * self-FK (nulo = "sem pai", dose de partida). `transitions_to_product_id` é metadado
 * informativo (ex.: 3ª dose de V8 "vira" V10) — não automatiza troca de protocolo do plano
 * nesta fatia (ver limitação registrada no contrato de API).
 */
return new class extends Migration
{
    private const ANCHOR_CONSTRAINT = 'immunization_protocol_doses_anchor_check';

    public function up(): void
    {
        Schema::create('immunization_protocol_doses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_id')->constrained('immunization_protocols')->cascadeOnDelete();
            $table->unsignedTinyInteger('dose_number');
            $table->unsignedSmallInteger('interval_days')->nullable();
            $table->foreignId('depends_on_dose_id')->nullable()
                ->constrained('immunization_protocol_doses')->nullOnDelete();
            $table->string('anchor', 20)->default(ImmunizationDoseAnchor::LAST_APPLICATION->value);
            $table->foreignId('transitions_to_product_id')->nullable()
                ->constrained('immunization_products')->nullOnDelete();
            $table->unsignedSmallInteger('min_age_days')->nullable();
            $table->unsignedSmallInteger('max_age_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['protocol_id', 'dose_number']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement($this->checkStatement('anchor', self::ANCHOR_CONSTRAINT, ImmunizationDoseAnchor::values()));
    }

    public function down(): void
    {
        Schema::dropIfExists('immunization_protocol_doses');
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

        return 'ALTER TABLE immunization_protocol_doses ADD CONSTRAINT '.$constraint
            ." CHECK ({$column}::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
