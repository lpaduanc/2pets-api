<?php

use App\Enums\OrganizationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 do split Pessoa/Organização: `organizations` é a EMPRESA (CNPJ), sem login.
 *
 * Hoje uma conta `clinic_owner`/`petshop_owner` é ao mesmo tempo a pessoa que loga e a
 * organização — `users.professionals` mistura os dois. Esta tabela existe para que a
 * organização passe a ter identidade própria, permitindo N pessoas vinculadas a ela
 * (`organization_members`, ver migration seguinte).
 *
 * Endereço/geo são COPIADOS de `users` nesta fase (ver `OrganizationBackfillService`) e
 * NÃO removidos de lá: `users.location` tem o índice GIST que `ProfessionalSearchService`
 * usa hoje para a busca geográfica — mexer nisso é uma fase própria, fora deste escopo.
 */
return new class extends Migration
{
    private const TYPE_CHECK_CONSTRAINT = 'organizations_organization_type_check';

    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_type', 20);
            $table->string('business_name')->nullable();
            $table->string('cnpj', 14)->nullable()->unique();
            $table->text('description')->nullable();

            $table->time('opening_hours')->nullable();
            $table->time('closing_hours')->nullable();
            $table->json('working_days')->nullable();
            $table->integer('service_radius_km')->nullable();
            $table->json('services_offered')->nullable();
            $table->json('products_sold')->nullable();

            // Endereço — copiado de `users` no backfill, ver nota da classe.
            $table->string('address')->nullable();
            $table->string('number')->nullable();
            $table->string('complement')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            // Responsável técnico: preferimos a FK para `professionals` (pessoa com CRMV);
            // os campos de texto são o fallback para RT sem perfil próprio na plataforma
            // (ver `OrganizationBackfillService::resolveTechnicalResponsibleProfessionalId`).
            $table->foreignId('technical_responsible_professional_id')
                ->nullable()
                ->constrained('professionals')
                ->nullOnDelete();
            $table->string('technical_responsible_name')->nullable();
            $table->string('technical_responsible_crmv')->nullable();
            $table->string('technical_responsible_crmv_state', 2)->nullable();
            $table->boolean('technical_responsible_verified')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_type');
        });

        $this->addOrganizationTypeCheckConstraint();
        $this->addGeographyColumn();
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }

    private function addOrganizationTypeCheckConstraint(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $allowedValues = implode(',', array_map(
            fn (string $value): string => "'{$value}'",
            OrganizationType::values()
        ));

        DB::statement(
            'ALTER TABLE organizations ADD CONSTRAINT '.self::TYPE_CHECK_CONSTRAINT.
            " CHECK (organization_type IN ({$allowedValues}))"
        );
    }

    /**
     * `location geography(POINT,4326)` não existe no Schema builder do Laravel — igual
     * `users`/`locations`, é SQL cru guardado por driver (ver `HasGeoPoint`, que sincroniza
     * esta coluna a partir de `latitude`/`longitude` a cada `save()`).
     */
    private function addGeographyColumn(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE organizations ADD COLUMN location geography(POINT, 4326)');
        DB::statement('CREATE INDEX idx_organizations_location ON organizations USING GIST (location)');
    }
};
