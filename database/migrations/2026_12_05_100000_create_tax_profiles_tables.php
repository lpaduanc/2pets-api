<?php

use App\Enums\FiscalCfopOperation;
use App\Enums\FiscalCfopScope;
use App\Enums\GoodsOrigin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil tributário — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §2. Mora no grupo de
 * produto (com sobrescrita no produto e um padrão por dono), porque o contador classifica por
 * família de mercadoria, não item a item.
 *
 * Alíquotas e percentuais com 4 casas (PLANO §3). CSOSN e CST convivem na mesma linha: qual vale
 * depende do regime da organização no momento da emissão (`TaxProfileRuleFactory`).
 *
 * Um padrão por dono: índice único PARCIAL (o par organização/profissional segue a regra de
 * `CommercialScopeResolver` — com organização, ela é a dona; sem, o profissional).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->char('origin', 1)->default(GoodsOrigin::NATIONAL->value);
            $table->string('icms_csosn', 3)->nullable();
            $table->string('icms_cst', 2)->nullable();
            $table->decimal('icms_rate', 7, 4)->default(0);
            $table->decimal('icms_base_reduction_percent', 7, 4)->default(0);
            $table->decimal('icms_sn_credit_rate', 7, 4)->default(0);
            $table->decimal('fcp_rate', 7, 4)->default(0);
            $table->string('pis_cst', 2)->nullable();
            $table->decimal('pis_rate', 7, 4)->default(0);
            $table->string('cofins_cst', 2)->nullable();
            $table->decimal('cofins_rate', 7, 4)->default(0);
            $table->string('benefit_code', 10)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active']);
            $table->index(['professional_id', 'active']);
        });

        Schema::create('tax_profile_cfops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_profile_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 30);
            $table->string('scope', 15);
            $table->char('cfop', 4);
            $table->timestamps();

            $table->unique(['tax_profile_id', 'operation', 'scope']);
        });

        Schema::table('product_groups', function (Blueprint $table): void {
            $table->foreignId('tax_profile_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('tax_profile_id')->nullable()->constrained()->nullOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX tax_profiles_default_per_organization ON tax_profiles (organization_id) WHERE is_default AND deleted_at IS NULL AND organization_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX tax_profiles_default_per_professional ON tax_profiles (professional_id) WHERE is_default AND deleted_at IS NULL AND organization_id IS NULL');
        DB::statement($this->check('tax_profiles', 'tax_profiles_origin_check', 'origin', GoodsOrigin::values()));
        DB::statement($this->check('tax_profile_cfops', 'tax_profile_cfops_operation_check', 'operation', FiscalCfopOperation::values()));
        DB::statement($this->check('tax_profile_cfops', 'tax_profile_cfops_scope_check', 'scope', FiscalCfopScope::values()));
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tax_profile_id');
        });

        Schema::table('product_groups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tax_profile_id');
        });

        Schema::dropIfExists('tax_profile_cfops');
        Schema::dropIfExists('tax_profiles');
    }

    /**
     * CHECK não aceita binding: cada valor (de enum de código, nunca de usuário) passa por
     * `quote()` mesmo assim.
     *
     * @param  list<string>  $values
     */
    private function check(string $table, string $name, string $column, array $values): string
    {
        $quoted = implode(', ', array_map(static fn (string $value): string => DB::getPdo()->quote($value), $values));

        return "ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column}::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
