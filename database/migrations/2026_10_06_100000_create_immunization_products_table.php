<?php

use App\Enums\ImmunizationGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo unificado de vacina/vermífugo/antiparasitário — contrato
 * docs/gap-simplesvet/specs/13-protocolos-vacinais-spec.md. Substitui `vaccine_catalog` para
 * cadastro NOVO (a tabela antiga permanece intacta, só leitura histórica — ver migration de
 * seed que copia os 11 registros existentes).
 *
 * `organization_id` (nomeado `company_id` na spec — o termo real deste domínio é
 * `organizations`, não `companies`/parceiro B2B) nulo = catálogo global da plataforma, gerido
 * fora desta API (seed curado); toda escrita via API exige organização ativa
 * (`OrganizationCatalogGate`).
 */
return new class extends Migration
{
    private const GROUP_CONSTRAINT = 'immunization_products_group_check';

    public function up(): void
    {
        Schema::create('immunization_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('group', 20);
            $table->string('manufacturer')->nullable();
            $table->text('description')->nullable();
            $table->boolean('legally_required')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'group']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement($this->checkStatement('group', self::GROUP_CONSTRAINT, ImmunizationGroup::values()));
    }

    public function down(): void
    {
        Schema::dropIfExists('immunization_products');
    }

    /**
     * @param  list<string>  $values
     */
    private function checkStatement(string $column, string $constraint, array $values): string
    {
        $quoted = implode(', ', array_map(
            static fn (string $value): string => DB::getPdo()->quote($value),
            $values,
        ));

        // `group` é palavra reservada do Postgres — precisa de aspas duplas como
        // identificador, senão o parser lê `group` como a cláusula `GROUP BY`.
        return 'ALTER TABLE immunization_products ADD CONSTRAINT '.$constraint
            ." CHECK (\"{$column}\"::text = ANY (ARRAY[{$quoted}]::text[]))";
    }
};
