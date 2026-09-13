<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `exam_results` — one row per parameter reported for a lab exam
 * (e.g. "Hemoglobina: 14.2 g/dL, normal").
 *
 * The `ExamResult` model and `ExamService::addResults()`/`getExamHistory()`
 * already read and write this table; no migration had ever created it, so
 * `POST /exams/{examId}/results` failed with `relation "exam_results" does
 * not exist` on every call.
 *
 * `value`/`reference_range` are plain strings on purpose, matching the
 * existing validation in `ExamController::addResults` and the shape
 * `ExamService::addResults` already writes — not the moment to change that
 * contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exam_results')) {
            return;
        }

        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->string('parameter', 120);
            $table->string('value');
            $table->string('unit', 40)->nullable();
            $table->string('reference_range')->nullable();
            $table->string('status', 20)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('exam_id');
            $table->index('parameter');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_results');
    }
};
