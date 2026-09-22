<?php

use App\Enums\PackageValidityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md — catálogo.
 *
 * `ServicePackage` é o terceiro tipo de `App\Contracts\Sellable` (produto e serviço já
 * implementam), reaproveitando o `sale_items` polimórfico existente — nenhuma tabela de venda
 * nova. `organization_id`/`professional_id` nullable seguem o mesmo padrão do resto do módulo
 * comercial (`CommercialScopeResolver`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 14, 2);

            $table->string('validity_type', 20)->default(PackageValidityType::DAYS_FROM_SALE->value);
            $table->unsignedInteger('validity_days')->nullable();
            $table->date('fixed_expires_at')->nullable();

            // Pacote família: um segundo pet do mesmo tutor pode consumir. Padrão false —
            // ver regra de negócio 2 do doc.
            $table->boolean('allow_transfer_between_pets')->default(false);

            $table->decimal('commission_percent', 8, 4)->nullable();
            $table->boolean('show_in_price_list')->default(true);
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'active']);
            $table->index(['professional_id', 'active']);
        });

        Schema::create('service_package_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['service_package_id', 'service_id']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();
        $values = implode("','", PackageValidityType::values());

        $connection->statement(
            "ALTER TABLE service_packages ADD CONSTRAINT service_packages_validity_type_check CHECK (validity_type IN ('{$values}'))"
        );

        // Coerência de validade: dias exige `days_from_sale`, data exige `fixed_date` — sem
        // isto o cadastro aceitaria um pacote "dias a partir da venda" sem nenhum prazo.
        $connection->statement(
            "ALTER TABLE service_packages ADD CONSTRAINT service_packages_validity_fields_check CHECK (
                (validity_type = 'days_from_sale' AND validity_days IS NOT NULL AND fixed_expires_at IS NULL) OR
                (validity_type = 'fixed_date' AND fixed_expires_at IS NOT NULL AND validity_days IS NULL) OR
                (validity_type = 'unlimited' AND validity_days IS NULL AND fixed_expires_at IS NULL)
            )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('service_package_items');
        Schema::dropIfExists('service_packages');
    }
};
