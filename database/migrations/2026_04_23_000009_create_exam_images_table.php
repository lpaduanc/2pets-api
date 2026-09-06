<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `exam_images` — attachments (PDFs/images) for a lab exam.
 *
 * The Exam model and ExamImage model already reference this table, but no
 * migration had ever created it. Wave 2.1 (exam attachments for tutors) requires
 * the table to exist before the upload flow works at all.
 *
 * Security notes:
 *   - `file_path` is a path on the PRIVATE storage disk — never directly
 *     addressable via public URL. See FileUploadService::uploadForExam.
 *   - `uploader_id` is logged so we can audit who uploaded what (tutor vs vet).
 *   - `disk` lets us migrate between local/s3 without rewriting paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exam_images')) {
            return;
        }

        Schema::create('exam_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('uploader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->string('image_type', 40)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('exam_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_images');
    }
};
