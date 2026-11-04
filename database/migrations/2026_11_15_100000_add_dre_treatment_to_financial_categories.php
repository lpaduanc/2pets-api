<?php

use App\Enums\DreTreatment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DRE gerencial (docs/gap-petflow/PLANO.md, 1.7): como cada categoria entra no terceiro regime.
 * Padrão `operating`; as categorias do SISTEMA que repetem valor do snapshot de rentabilidade
 * (taxa de cartão, compra de mercadoria, receita e devolução de venda do PDV) recebem o
 * tratamento que as tira da gerencial (`DreTreatment::systemDefaults`). A clínica edita depois.
 * Caixa e competência não leem a coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_categories', function (Blueprint $table): void {
            $table->string('dre_treatment', 30)->default(DreTreatment::OPERATING->value);
        });

        foreach (DreTreatment::systemDefaults() as $nature => $categories) {
            foreach ($categories as $name => $treatment) {
                DB::table('financial_categories')
                    ->where('is_system', true)
                    ->where('nature', $nature)
                    ->where('name', $name)
                    ->update(['dre_treatment' => $treatment->value]);
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                "ALTER TABLE financial_categories ADD CONSTRAINT financial_categories_dre_treatment_check CHECK (dre_treatment IN ('%s'))",
                implode("','", DreTreatment::values())
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE financial_categories DROP CONSTRAINT IF EXISTS financial_categories_dre_treatment_check');
        }

        Schema::table('financial_categories', function (Blueprint $table): void {
            $table->dropColumn('dre_treatment');
        });
    }
};
