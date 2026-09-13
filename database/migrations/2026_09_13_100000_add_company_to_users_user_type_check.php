<?php

use App\Enums\ProfessionalType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `users.user_type` aceita `company`, que é o lead B2B (parceiro de clube de vantagens) —
 * ver `RegisterRequest` e `docs/taxonomia-professional-type.md` §6.
 *
 * Por que estava faltando: `2025_11_28_163205_add_registration_fields_to_users_table` criou a
 * coluna com `$table->enum('user_type', [...8 valores...])`, e no Postgres o `enum()` do Laravel
 * vira VARCHAR + CHECK constraint. Depois disso:
 *
 *   - `2025_12_06_213554_add_company_to_user_type_enum` foi escrita como no-op, na suposição de
 *     que "no PostgreSQL string aceita qualquer valor" — falso, porque o CHECK já existia;
 *   - `2026_04_04_000003_fix_enum_columns_for_postgresql` converteu a coluna para VARCHAR com
 *     `->change()`, mas `change()` NÃO derruba CHECK preexistente, então a constraint sobreviveu
 *     à conversão sem nunca ganhar `company`.
 *
 * Resultado em produção: `POST /register` com `user_type=company` estourava `QueryException`
 * 23514 (violação de CHECK) — o cadastro de empresa parceira era inalcançável na etapa 1, e
 * `completeCompany()` inteiro era código morto por consequência.
 *
 * A lista é derivada de `ProfessionalType` em vez de literal, para a constraint não divergir de
 * novo quando a taxonomia mudar — mesmo padrão de `professionals_professional_type_check`.
 */
return new class extends Migration
{
    private const CONSTRAINT_NAME = 'users_user_type_check';

    /**
     * Tipos que não são de negócio profissional: tutor (pessoa física) e company (lead B2B).
     *
     * @var list<string>
     */
    private const NON_PROFESSIONAL_USER_TYPES = ['tutor', 'company'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT_NAME);

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT '.self::CONSTRAINT_NAME.
            ' CHECK (user_type IS NULL OR user_type IN ('.$this->allowedValues().'))'
        );
    }

    /**
     * Volta à lista anterior (sem `company`). Só é seguro se nenhuma conta `company` existir —
     * por isso as linhas são removidas do caminho do CHECK antes, e não à força: reverter esta
     * migration com lead B2B cadastrado é erro de operação, não algo a silenciar.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT_NAME);
    }

    private function allowedValues(): string
    {
        $values = [...self::NON_PROFESSIONAL_USER_TYPES, ...ProfessionalType::values()];

        return implode(',', array_map(
            fn (string $value): string => "'{$value}'",
            $values
        ));
    }
};
