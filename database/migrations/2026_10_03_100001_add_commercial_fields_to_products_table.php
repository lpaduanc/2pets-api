<?php

use App\Enums\ProductPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/08-produtos-precificacao-lista-precos.md — campos do produto.
 *
 * `products` nasceu para e-commerce (`create_ecommerce_tables`): tinha nome, preço, SKU e
 * estoque, mas nada do que um balcão precisa — custo, markup, unidade de venda, código de
 * barras, fiscal, comissão e as flags de comportamento. Esta migration transforma a tabela
 * no CATÁLOGO COMERCIAL da organização sem quebrar carrinho/pedido, que continuam lendo as
 * mesmas colunas antigas.
 *
 * `inventories` NÃO é aposentada, ao contrário do que o documento sugere: `inventories.id` é
 * FK de `vaccinations` e `dewormings` desde
 * `2026_09_15_110000_add_inventory_link_to_vaccinations_and_dewormings`, e é o que
 * `ClinicalStockDeductionService` decrementa. Migrar o cadastro quebraria a baixa clínica em
 * troca de nada. Divisão adotada: `inventories` = insumo clínico, `products` = catálogo de
 * venda.
 *
 * `sku` deixa de ser único GLOBAL e passa a ser único POR DONO. O índice antigo impedia que
 * duas clínicas cadastrassem o mesmo código — bug de multi-tenancy que só não apareceu porque
 * ninguém ainda vendia produto de verdade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('product_group_id')->nullable()->after('category_id')->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->after('product_group_id')->constrained()->nullOnDelete();

            $table->string('code', 60)->nullable()->after('sku');
            $table->string('gtin', 14)->nullable()->after('code');
            $table->string('ncm', 8)->nullable()->after('gtin');
            $table->string('cest', 7)->nullable()->after('ncm');
            $table->string('unit_of_sale', 10)->default('UN')->after('cest');
            $table->string('purpose', 20)->default(ProductPurpose::RESALE->value)->after('unit_of_sale');

            // Custo médio move a cada compra (doc 06); `last_cost` é o da última entrada.
            // Guardamos os dois porque a margem da comissão (doc 09) usa o médio e a
            // reprecificação por markup usa o último.
            $table->decimal('average_cost', 12, 4)->default(0)->after('price');
            $table->decimal('last_cost', 12, 4)->default(0)->after('average_cost');
            $table->decimal('markup_percent', 8, 4)->nullable()->after('last_cost');
            $table->decimal('commission_percent', 8, 4)->nullable()->after('markup_percent');

            $table->boolean('show_in_price_list')->default(true)->after('track_inventory');
            $table->boolean('allow_price_override')->default(true)->after('show_in_price_list');
            $table->boolean('controls_stock')->default(true)->after('allow_price_override');
            $table->boolean('track_batches')->default(false)->after('controls_stock');
            $table->integer('min_stock')->default(0)->after('track_batches');
            $table->integer('max_stock')->nullable()->after('min_stock');
            $table->date('expiry_date')->nullable()->after('max_stock');

            $table->softDeletes();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();

        $connection->statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_sku_unique');
        $connection->statement(
            'CREATE UNIQUE INDEX products_org_sku_unique ON products (organization_id, lower(sku)) WHERE deleted_at IS NULL AND organization_id IS NOT NULL'
        );
        $connection->statement(
            'CREATE UNIQUE INDEX products_professional_sku_unique ON products (professional_id, lower(sku)) WHERE deleted_at IS NULL AND organization_id IS NULL'
        );

        $connection->statement(sprintf(
            "ALTER TABLE products ADD CONSTRAINT products_purpose_check CHECK (purpose IN ('%s'))",
            implode("','", ProductPurpose::values())
        ));

        // Leitura de código de barras no PDV precisa ser indexada (critério de aceite do 08).
        $connection->statement(
            'CREATE INDEX products_gtin_index ON products (gtin) WHERE gtin IS NOT NULL AND deleted_at IS NULL'
        );

        // Busca textual do balcão, mesmo dialeto dos índices de `services`
        // (`2026_09_21_100001_add_semantic_search_trigram_indexes`).
        $connection->statement(
            'CREATE INDEX idx_products_name_unaccent_trgm ON products USING gin (immutable_unaccent(name) gin_trgm_ops) WHERE is_active = true AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $connection = Schema::getConnection();
            $connection->statement('DROP INDEX IF EXISTS idx_products_name_unaccent_trgm');
            $connection->statement('DROP INDEX IF EXISTS products_gtin_index');
            $connection->statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_purpose_check');
            $connection->statement('DROP INDEX IF EXISTS products_professional_sku_unique');
            $connection->statement('DROP INDEX IF EXISTS products_org_sku_unique');
            $connection->statement('ALTER TABLE products ADD CONSTRAINT products_sku_unique UNIQUE (sku)');
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropConstrainedForeignId('brand_id');
            $table->dropConstrainedForeignId('product_group_id');
            $table->dropColumn([
                'code', 'gtin', 'ncm', 'cest', 'unit_of_sale', 'purpose',
                'average_cost', 'last_cost', 'markup_percent', 'commission_percent',
                'show_in_price_list', 'allow_price_override', 'controls_stock',
                'track_batches', 'min_stock', 'max_stock', 'expiry_date',
            ]);
        });
    }
};
