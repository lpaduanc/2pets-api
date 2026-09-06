<?php

use App\Enums\ProfessionalType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trava `professionals.professional_type` nos 7 valores canônicos de `ProfessionalType`
 * via CHECK constraint — sem isso, nada no schema impedia um 8º valor divergente de
 * voltar a aparecer (a causa-raiz documentada em `docs/taxonomia-professional-type.md`).
 *
 * Depende da migration anterior (`normalize_legacy_professional_types`) já ter corrigido
 * as 4 linhas legadas; roda depois dela na ordem alfabética/timestamp.
 */
return new class extends Migration
{
    private const CONSTRAINT_NAME = 'professionals_professional_type_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $allowedValues = implode(',', array_map(
            fn (string $value): string => "'{$value}'",
            ProfessionalType::values()
        ));

        DB::statement(
            'ALTER TABLE professionals ADD CONSTRAINT '.self::CONSTRAINT_NAME.
            " CHECK (professional_type IN ({$allowedValues}))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE professionals DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT_NAME);
    }
};
