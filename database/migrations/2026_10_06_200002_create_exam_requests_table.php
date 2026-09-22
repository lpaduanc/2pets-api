<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido de exame como documento — contrato docs/gap-simplesvet/specs/
 * 16-modelos-exame-laudos-spec.md. `exam_type_ids` do desenho original vira uma pivô de
 * verdade (`exam_request_exam_type`) em vez de array solto — o pedido pode listar mais de um
 * tipo de exame (ex.: "hemograma + bioquímico").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('clinical_notes')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('pet_id');
        });

        Schema::create('exam_request_exam_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_type_id')->constrained()->restrictOnDelete();

            $table->unique(['exam_request_id', 'exam_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_request_exam_type');
        Schema::dropIfExists('exam_requests');
    }
};
