<?php

use App\Enums\PetSpecies;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pivô multi-espécie de um produto de imunização — regra de negócio 1 da spec 13.
 * `species` é o enum PHP `PetSpecies` já canônico do domínio, não uma tabela nova
 * (decisão explícita da spec: não inventar uma terceira fonte de verdade de espécie).
 */
return new class extends Migration
{
    private const SPECIES_CONSTRAINT = 'immunization_product_species_species_check';

    public function up(): void
    {
        Schema::create('immunization_product_species', function (Blueprint $table) {
            $table->id();
            $table->foreignId('immunization_product_id')->constrained()->cascadeOnDelete();
            $table->string('species', 20);
            $table->timestamps();

            $table->unique(['immunization_product_id', 'species']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement($this->checkStatement('species', self::SPECIES_CONSTRAINT, PetSpecies::values()));
    }

    public function down(): void
    {
        Schema::dropIfExists('immunization_product_species');
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

        return 'ALTER TABLE immunization_product_species ADD CONSTRAINT '.$constraint
            ." CHECK ({$column}::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
