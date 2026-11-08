<?php

use App\Enums\FiscalEnvironment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ambiente, série por modelo e estado de contingência do emitente — spec
 * docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §2 e §4. Padrão HOMOLOGAÇÃO: emitir em
 * produção é escolha explícita do dono. A série padrão "1" é a que a SEFAZ sugere para quem
 * começa; quem vem de outro sistema troca série e próximo número em `fiscal-settings`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('fiscal_environment', 15)->default(FiscalEnvironment::HOMOLOGATION->value);
            $table->string('nfe_series', 3)->default('1');
            $table->string('nfce_series', 3)->default('1');
            $table->timestamp('fiscal_contingency_started_at')->nullable();
            $table->string('fiscal_contingency_reason')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $quoted = implode(', ', array_map(static fn (string $value): string => DB::getPdo()->quote($value), FiscalEnvironment::values()));
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_fiscal_environment_check CHECK (fiscal_environment::text = ANY (ARRAY[{$quoted}]::text[]))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_fiscal_environment_check');
        }

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn([
                'fiscal_environment', 'nfe_series', 'nfce_series',
                'fiscal_contingency_started_at', 'fiscal_contingency_reason',
            ]);
        });
    }
};
