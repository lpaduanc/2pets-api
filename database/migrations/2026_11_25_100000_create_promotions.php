<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promoções (docs/gap-petflow/PLANO.md, 3.2). A regra de cada tipo fica em `config` (json),
 * validado por tipo no request — no lugar das tabelas `promotion_items`/`promotion_targets` do
 * plano: alvo e itens são poucos, sempre lidos junto com a promoção, e o contrato do front já é
 * esse `config`.
 *
 * `sale_items.promotion_discount` + `promotion_id`: o desconto de promoção da linha, separado do
 * manual (`discount`). total da linha = quantidade × preço − promoção − manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 30);
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('stackable')->default(false);
            $table->boolean('active')->default(true);
            $table->json('config');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active']);
        });

        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_type_check CHECK (type IN ('buy_x_pay_y', 'bundle', 'category_discount', 'brand_discount'))");

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->foreignId('promotion_id')->nullable()->after('discount')->constrained()->nullOnDelete();
            $table->decimal('promotion_discount', 12, 2)->default(0)->after('promotion_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn('promotion_discount');
        });

        Schema::dropIfExists('promotions');
    }
};
