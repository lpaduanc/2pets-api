<?php

use App\Enums\StockMovementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Granel, abertura de embalagem e balança (docs/gap-petflow/PLANO.md, 3.3).
 *
 * - `product_conversions`: o saco (`from`) vira `factor` unidades do granel (`to`), um par por
 *   escopo (organização, ou o vet volante sem organização).
 * - `package_openings`: o registro de cada abertura — o documento de origem dos dois movimentos
 *   (`package_open_out` no saco, `package_open_in` no granel).
 * - `products.scale_code` (código da balança, único no escopo) e `sold_by_weight`.
 * - `organizations.scale_settings`: prefixos, comprimento do código e do valor e o modo
 *   (`weight` = gramas, `price` = centavos) da etiqueta EAN-13 da balança.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('to_product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('factor', 12, 4);
            $table->timestamps();

            $table->unique(['from_product_id', 'to_product_id']);
        });

        DB::statement('ALTER TABLE product_conversions ADD CONSTRAINT product_conversions_factor_check CHECK (factor > 0)');
        DB::statement('ALTER TABLE product_conversions ADD CONSTRAINT product_conversions_distinct_check CHECK (from_product_id <> to_product_id)');

        Schema::create('package_openings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_conversion_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('from_product_id')->constrained('products');
            $table->foreignId('to_product_id')->constrained('products');
            $table->decimal('quantity', 12, 4);
            $table->decimal('factor', 12, 4);
            $table->decimal('entered_quantity', 12, 4);
            $table->decimal('total_cost', 14, 2);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('scale_code', 6)->nullable();
            $table->boolean('sold_by_weight')->default(false);
        });

        DB::statement('CREATE UNIQUE INDEX products_scale_code_org_unique ON products (organization_id, scale_code) WHERE scale_code IS NOT NULL AND organization_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX products_scale_code_pro_unique ON products (professional_id, scale_code) WHERE scale_code IS NOT NULL AND organization_id IS NULL AND deleted_at IS NULL');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->json('scale_settings')->nullable();
        });

        $this->replaceTypeCheck(StockMovementType::values());
    }

    public function down(): void
    {
        DB::table('stock_movements')->whereIn('type', ['package_open_out', 'package_open_in'])->delete();
        $this->replaceTypeCheck(array_values(array_diff(StockMovementType::values(), ['package_open_out', 'package_open_in'])));

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('scale_settings');
        });

        DB::statement('DROP INDEX IF EXISTS products_scale_code_org_unique');
        DB::statement('DROP INDEX IF EXISTS products_scale_code_pro_unique');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['scale_code', 'sold_by_weight']);
        });

        Schema::dropIfExists('package_openings');
        Schema::dropIfExists('product_conversions');
    }

    /** @param  list<string>  $types */
    private function replaceTypeCheck(array $types): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_type_check');
        DB::statement(sprintf("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN ('%s'))", implode("','", $types)));
    }
};
