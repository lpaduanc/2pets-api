<?php

use App\Enums\SoldPackageStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md — instância
 * vendida (crédito de sessões).
 *
 * `status` só é gravado como `cancelled` por ação explícita; `active`/`consumed`/`expired` são
 * o status EFETIVO (derivado em query, ver `App\Models\SoldPackage::effectiveStatus()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sold_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_package_id')->constrained();

            $table->foreignId('client_id')->constrained('users');
            $table->foreignId('pet_id')->constrained();

            $table->timestamp('sold_at');
            $table->date('expires_at')->nullable();

            $table->string('status', 20)->default(SoldPackageStatus::ACTIVE->value);
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'client_id']);
            $table->index(['pet_id', 'status']);
            $table->index('sale_id');
        });

        Schema::create('sold_package_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sold_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained();
            $table->unsignedInteger('quantity_total');
            $table->unsignedInteger('quantity_used')->default(0);
            $table->timestamps();

            $table->unique(['sold_package_id', 'service_id']);
        });

        Schema::create('sold_package_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sold_package_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('medical_record_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamp('consumed_at');
            $table->foreignId('user_id')->constrained();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('sold_package_item_id');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $values = implode("','", SoldPackageStatus::values());
        Schema::getConnection()->statement(
            "ALTER TABLE sold_packages ADD CONSTRAINT sold_packages_status_check CHECK (status IN ('{$values}'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sold_package_consumptions');
        Schema::dropIfExists('sold_package_items');
        Schema::dropIfExists('sold_packages');
    }
};
