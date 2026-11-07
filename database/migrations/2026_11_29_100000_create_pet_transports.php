<?php

use App\Enums\PetTransportStatus;
use App\Enums\PetTransportType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leva e traz — docs/gap-petflow/PLANO.md, 3.7.
 *
 *  - Zona SEM cadastro paralelo: a área de atendimento (`service_areas`) ganha a regra do leva e
 *    traz (lista de CEPs/faixas OU raio em km a partir da clínica) e a taxa. Colunas com prefixo
 *    `delivery_` porque a área já tem outro sentido (elegibilidade de serviço, item 21).
 *  - `services.system_key`: o serviço "Leva e traz" é criado sob demanda e achado por esta chave
 *    (o nome e o código são editáveis pelo usuário; a chave não).
 *  - `pet_transports`: um por agendamento, com endereço, zona, distância, taxa congelada, linha
 *    de cobrança (`appointment_service_id`), motorista, status e os horários de cada passo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_areas', function (Blueprint $table): void {
            $table->boolean('delivery_enabled')->default(false);
            $table->string('delivery_rule_type', 10)->nullable();
            $table->json('delivery_ceps')->nullable();
            $table->decimal('delivery_max_km', 8, 2)->nullable();
            $table->decimal('delivery_fee', 12, 2)->nullable();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->string('system_key', 30)->nullable();
        });

        Schema::create('pet_transports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('appointment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('service_area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_service_id')->nullable()->constrained('appointment_services')->nullOnDelete();
            $table->string('type', 12);
            $table->string('zip_code', 9)->nullable();
            $table->string('address')->nullable();
            $table->string('complement')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 2)->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->decimal('fee', 12, 2)->default(0);
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default(PetTransportStatus::PENDING->value);
            $table->timestamp('scheduled_pickup_at')->nullable();
            $table->timestamp('on_the_way_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'scheduled_pickup_at']);
            $table->index(['professional_id', 'scheduled_pickup_at']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $connection = Schema::getConnection();
        $connection->statement(sprintf("ALTER TABLE pet_transports ADD CONSTRAINT pet_transports_type_check CHECK (type IN ('%s'))", implode("','", PetTransportType::values())));
        $connection->statement(sprintf("ALTER TABLE pet_transports ADD CONSTRAINT pet_transports_status_check CHECK (status IN ('%s'))", implode("','", PetTransportStatus::values())));
        $connection->statement('ALTER TABLE pet_transports ADD CONSTRAINT pet_transports_fee_check CHECK (fee >= 0)');
        $connection->statement("ALTER TABLE service_areas ADD CONSTRAINT service_areas_delivery_rule_check CHECK (delivery_rule_type IS NULL OR delivery_rule_type IN ('cep','distance'))");
        $connection->statement('CREATE UNIQUE INDEX services_org_system_key_unique ON services (organization_id, system_key) WHERE system_key IS NOT NULL AND organization_id IS NOT NULL AND deleted_at IS NULL');
        $connection->statement('CREATE UNIQUE INDEX services_professional_system_key_unique ON services (professional_id, system_key) WHERE system_key IS NOT NULL AND organization_id IS NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_transports');
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('system_key');
        });
        Schema::table('service_areas', function (Blueprint $table): void {
            $table->dropColumn(['delivery_enabled', 'delivery_rule_type', 'delivery_ceps', 'delivery_max_km', 'delivery_fee']);
        });
    }
};
