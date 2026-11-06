<?php

use App\Enums\CommissionTrigger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PLANO 2.6: `commission_rules.trigger` (on_sale · on_receipt · on_execution) substitui o booleano
 * `only_when_received`, que SAI do banco aqui.
 *
 * Por que remover em vez de deixar deprecado: o booleano nunca foi honrado (o fechamento sempre
 * exigiu recebimento), e duas colunas dizendo a mesma coisa divergem no primeiro update que só
 * mexer numa delas. A compatibilidade fica na borda HTTP: o request ainda aceita
 * `only_when_received` (true → on_receipt, false → on_sale) e o resource ainda devolve o campo,
 * derivado do gatilho.
 *
 * Conversão: true → on_receipt; false → on_sale. O `down()` volta o booleano (on_receipt → true,
 * os outros → false) — `on_execution` não tem equivalente no modelo antigo e vira `false`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_rules', function (Blueprint $table): void {
            $table->string('trigger', 20)->default(CommissionTrigger::ON_RECEIPT->value)->after('calculation_base');
        });

        DB::table('commission_rules')->update([
            'trigger' => DB::raw(sprintf(
                "CASE WHEN only_when_received THEN '%s' ELSE '%s' END",
                CommissionTrigger::ON_RECEIPT->value,
                CommissionTrigger::ON_SALE->value,
            )),
        ]);

        DB::statement(sprintf(
            "ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_trigger_check CHECK (\"trigger\" IN ('%s'))",
            implode("', '", CommissionTrigger::values()),
        ));

        Schema::table('commission_rules', function (Blueprint $table): void {
            $table->dropColumn('only_when_received');
        });
    }

    public function down(): void
    {
        Schema::table('commission_rules', function (Blueprint $table): void {
            $table->boolean('only_when_received')->default(true)->after('calculation_base');
        });

        DB::table('commission_rules')->update([
            'only_when_received' => DB::raw(sprintf("\"trigger\" = '%s'", CommissionTrigger::ON_RECEIPT->value)),
        ]);

        DB::statement('ALTER TABLE commission_rules DROP CONSTRAINT IF EXISTS commission_rules_trigger_check');

        Schema::table('commission_rules', function (Blueprint $table): void {
            $table->dropColumn('trigger');
        });
    }
};
