<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `exam_results.value`/`reference_range` são texto livre de propósito — há resultado
 * legitimamente não numérico ("Negativo", "Reagente", "<0,1", "Ausente"). Sem nenhum valor
 * numérico gravado, porém, não dá para ordenar/comparar no gráfico de tendência
 * (`GET /exams/pet/{petId}/history/{parameter}`) nem derivar `status` automaticamente —
 * hoje é só o que o operador digita à mão.
 *
 * `value_numeric`/`reference_min`/`reference_max` são preenchidos quando o texto original é
 * parseável (`App\DataTransferObjects\PtBrDecimal`, chamado por `ExamService::addResults`);
 * ficam `null` para os casos legitimamente não numéricos. `value`/`reference_range` textuais
 * continuam a fonte de verdade para exibição — estas colunas nunca os substituem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->decimal('value_numeric', 12, 4)->nullable()->after('value');
            $table->decimal('reference_min', 12, 4)->nullable()->after('reference_range');
            $table->decimal('reference_max', 12, 4)->nullable()->after('reference_min');
        });
    }

    public function down(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->dropColumn(['value_numeric', 'reference_min', 'reference_max']);
        });
    }
};
