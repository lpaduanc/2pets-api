<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistência pessoal do BI — contrato docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md.
 *
 * `dashboard_widgets` (o painel do usuário) e `favorite_indicators` (a aba "Favoritos") são
 * intencionalmente tabelas separadas, mesmo tendo colunas quase idênticas: um widget carrega
 * POSIÇÃO/TAMANHO no grid (layout), um favorito não tem layout nenhum — é só um atalho para
 * reabrir o mesmo indicador com a mesma configuração depois.
 *
 * `organization_id` nullable pela mesma razão de `sales`/`products` (doc 08): vet volante não
 * tem organização e ainda assim tem dashboard próprio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('indicator_key', 60);
            $table->json('config');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('size', 20)->default('medium');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'organization_id']);
        });

        Schema::create('favorite_indicators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('indicator_key', 60);
            $table->json('config');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorite_indicators');
        Schema::dropIfExists('dashboard_widgets');
    }
};
