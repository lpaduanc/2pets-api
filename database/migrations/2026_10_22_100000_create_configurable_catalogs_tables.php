<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item 23 do backlog gap-simplesvet — cadastros auxiliares configuráveis pela clínica/petshop.
 *
 * Decisão do coordenador (sobrepõe a proposta original da spec 23 de "catálogo global curado +
 * override de visibilidade"): estas seis tabelas seguem o padrão **por-dono** já em produção em
 * `product_groups`/`brands`/`stock_exit_reasons` — `organization_id` OU `professional_id`, nunca
 * os dois nulos, sem conceito de catálogo global compartilhado. `PetSpecies` continua enum
 * (migração para tabela fica fora de escopo desta rodada, alto custo de reversão).
 *
 * `holidays` e `hospitalization_boxes` têm campo extra próprio; as demais duas
 * (`coats`, `occupations`) são só `name` + o padrão.
 *
 * **`customer_sources`/`loss_reasons` REMOVIDAS desta migration** (consolidação pedida pelo
 * coordenador, nada commitado até então): item 18 já tinha criado `client_origins`/
 * `churn_reasons` para o mesmo conceito, com `ClientRelationshipProfile` referenciando essas
 * tabelas por FK — mantidas as do 18 para não quebrar a segmentação. Ver
 * `docs/gap-simplesvet/contratos/18-contrato-api.md`/`23-contrato-api.md`.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const SIMPLE_CATALOGS = ['coats', 'occupations'];

    public function up(): void
    {
        foreach (self::SIMPLE_CATALOGS as $table) {
            $this->createOwnerScopedTable($table);
        }

        Schema::create('holidays', function (Blueprint $table): void {
            $this->addOwnerScopedColumns($table);
            $table->date('date');
            $table->boolean('recurring_annually')->default(true);
        });

        Schema::create('hospitalization_boxes', function (Blueprint $table): void {
            $this->addOwnerScopedColumns($table);
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->text('notes')->nullable();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->createPartialUniqueIndexes([...self::SIMPLE_CATALOGS, 'holidays', 'hospitalization_boxes']);
    }

    public function down(): void
    {
        Schema::dropIfExists('hospitalization_boxes');
        Schema::dropIfExists('holidays');
        foreach (array_reverse(self::SIMPLE_CATALOGS) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createOwnerScopedTable(string $table): void
    {
        Schema::create($table, function (Blueprint $tableBlueprint): void {
            $this->addOwnerScopedColumns($tableBlueprint);
        });
    }

    /**
     * Mesmas colunas de `product_groups`/`brands`/`stock_exit_reasons`: dono
     * (`organization_id` OU `professional_id`), `name`, `active`, soft delete.
     */
    private function addOwnerScopedColumns(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
        $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
        $table->string('name');
        $table->boolean('active')->default(true);
        $table->timestamps();
        $table->softDeletes();

        $table->index(['organization_id', 'active']);
        $table->index(['professional_id', 'active']);
    }

    /**
     * Nome único por dono, entre os vivos — mesmo índice parcial de
     * `2026_10_03_100000_create_product_groups_and_brands_tables.php`.
     *
     * @param  list<string>  $tables
     */
    private function createPartialUniqueIndexes(array $tables): void
    {
        foreach ($tables as $table) {
            DB::statement(
                "CREATE UNIQUE INDEX {$table}_org_name_unique ON {$table} (organization_id, lower(name)) WHERE deleted_at IS NULL AND organization_id IS NOT NULL"
            );
            DB::statement(
                "CREATE UNIQUE INDEX {$table}_professional_name_unique ON {$table} (professional_id, lower(name)) WHERE deleted_at IS NULL AND organization_id IS NULL"
            );
        }
    }
};
