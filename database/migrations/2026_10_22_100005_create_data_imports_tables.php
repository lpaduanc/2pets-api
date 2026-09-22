<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 26 do backlog gap-simplesvet — importação de dados de outro sistema. `batch_uuid`
 * fica gravado por documentação/compatibilidade com o padrão já usado em `activity_log`, mas
 * o rollback real (`ImportRollbackService`) correlaciona por `data_import_rows.data_import_id`
 * — mais preciso que um batch_uuid solto, porque não corre risco de pegar atividade de outra
 * escrita que por acaso compartilhe o mesmo lote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20)->default('csv');
            $table->string('entity', 20);
            $table->string('file_path');
            $table->string('status', 20)->default('uploaded');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->json('column_mapping')->nullable();
            $table->json('options')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['professional_id', 'status']);
        });

        Schema::create('data_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('data_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->json('normalized')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('errors')->nullable();
            $table->string('created_record_type')->nullable();
            $table->unsignedBigInteger('created_record_id')->nullable();
            $table->unsignedBigInteger('matched_existing_id')->nullable();
            $table->timestamps();

            $table->index(['data_import_id', 'status']);
            $table->index(['created_record_type', 'created_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_import_rows');
        Schema::dropIfExists('data_imports');
    }
};
