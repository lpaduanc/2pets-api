<?php

use App\Enums\FinancialCategoryKind;
use App\Enums\FinancialNature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md — plano de contas.
 *
 * Árvore por auto-FK (`parent_id`). `kind = group` só agrupa (soma recursiva das filhas na
 * tela); só `kind = entry` (folha) recebe lançamento — validado na aplicação, não por CHECK
 * (Postgres não valida "FK condicional" nativamente; ver `FinancialEntryService::create()`).
 *
 * `is_system` marca as categorias do seed padrão (`FinancialCategoryProvisioner`): não podem
 * ser apagadas, só desativadas, porque lançamento histórico pode apontar para elas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('financial_categories')->cascadeOnDelete();

            $table->string('name');
            $table->string('nature', 10);
            $table->string('kind', 10)->default(FinancialCategoryKind::ENTRY->value);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active']);
            $table->index(['professional_id', 'active']);
            $table->index('parent_id');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();
        $checks = [
            ['nature', FinancialNature::values()],
            ['kind', FinancialCategoryKind::values()],
        ];

        foreach ($checks as [$column, $values]) {
            $connection->statement(sprintf(
                "ALTER TABLE financial_categories ADD CONSTRAINT financial_categories_%s_check CHECK (%s IN ('%s'))",
                $column, $column, implode("','", $values)
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_categories');
    }
};
