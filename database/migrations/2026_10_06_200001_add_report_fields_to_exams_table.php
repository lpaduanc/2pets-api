<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laudo estruturado — contrato docs/gap-simplesvet/specs/16-modelos-exame-laudos-spec.md.
 * Colunas nullable aditivas, sem migração de dado histórico (exames existentes continuam
 * válidos com `exam_type`/`exam_name` em texto livre — regra de negócio 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->foreignId('exam_type_id')->nullable()->after('exam_type')
                ->constrained('exam_types')->nullOnDelete();
            $table->text('report_html')->nullable();
            $table->text('findings')->nullable();
            $table->text('conclusion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_type_id');
            $table->dropColumn(['report_html', 'findings', 'conclusion']);
        });
    }
};
