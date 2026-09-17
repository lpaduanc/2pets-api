<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anexos (imagem/PDF) de um prontuário — disco privado, nunca URL pública direta.
 * Mesmo padrão de `exam_images` (ver `ExamController::downloadImage`).
 *
 * Nomes de coluna seguem docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md §2
 * literalmente (`path`, `original_name`, `mime`, `size`, `uploaded_by`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_record_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained('medical_records')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('medical_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_attachments');
    }
};
