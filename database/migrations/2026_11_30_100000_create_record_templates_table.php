<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelos de prontuário — o mínimo do doc 14 (docs/gap-simplesvet/14-tipos-de-atendimento-e-
 * modelos-de-prontuario.md), trazido pelo PLANO 3.8 para receber o modelo de anamnese por
 * sistemas. Separado de `document_templates` de propósito (o doc 14 manda): modelo de documento
 * é emitido e congelado; modelo de prontuário é ponto de partida do texto do atendimento.
 *
 * `organization_id` nulo = modelo GLOBAL do 2pets, visível a toda organização (mesmo desenho de
 * `DocumentTemplate::scopeVisibleTo`). `system_key` identifica o modelo semeado pelo sistema
 * (seeder idempotente); modelo criado pela clínica não tem chave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('system_key', 60)->nullable()->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->longText('body_html');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_templates');
    }
};
