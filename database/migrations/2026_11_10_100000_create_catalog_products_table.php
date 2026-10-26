<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo GLOBAL de produtos pet, curado pelo admin da plataforma (`/admin`).
 *
 * Diferente de `products`/`brands` (por organização/profissional): aqui não há dono. A clínica
 * ou pet shop só LÊ este catálogo e importa o item para o próprio `products`, informando o
 * preço — evita cadastrar à mão nome, marca, peso e EAN de cada ração.
 *
 * Deduplicação: `ean` quando existe (o mesmo produto tem o mesmo código de barras em qualquer
 * loja); `dedup_key` (marca + nome + peso normalizados) cobre item sem EAN confiável.
 *
 * Só dado factual é coletado de fontes externas; `description` é texto próprio, gerado a partir
 * dos atributos. Imagem vem de fonte licenciada por EAN (Cosmos/Bluesoft) ou upload do admin —
 * nunca copiada de loja (direito autoral).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('catalog_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_brand_id')->constrained('catalog_brands');
            $table->string('category', 40);
            $table->string('species', 20);
            $table->string('line_name');
            $table->string('name');
            $table->string('variant_label')->nullable();
            $table->unsignedInteger('net_weight_grams')->nullable();
            $table->string('ean', 14)->nullable()->unique();
            $table->string('dedup_key')->unique();
            $table->text('description')->nullable();
            $table->json('attributes')->nullable();
            $table->string('ncm', 10)->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_status', 20)->default('pending');
            $table->timestamp('image_checked_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'species']);
            $table->index('image_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_products');
        Schema::dropIfExists('catalog_brands');
    }
};
