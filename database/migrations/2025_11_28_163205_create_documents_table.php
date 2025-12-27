<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('document_type'); // crmv_certificate, diploma, cnpj, identity, etc.
            $table->string('file_name'); // Unique generated filename
            $table->string('file_path'); // Full storage path
            $table->string('file_type', 10); // pdf, jpg, png, jpeg, heic
            $table->integer('file_size'); // Size in bytes
            $table->string('original_name'); // Original filename from user
            $table->timestamps();

            // Indexes for quick lookups
            $table->index(['user_id', 'document_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
