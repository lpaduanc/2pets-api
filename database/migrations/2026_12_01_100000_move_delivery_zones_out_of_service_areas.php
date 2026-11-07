<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leva e traz (PLANO 3.7) — correção de modelagem. `service_areas` são SETORES (Clínica, Banho e
 * Tosa, Cirurgia) que restringem quem atende cada serviço, não áreas geográficas: com a zona lá
 * dentro, criar "Centro" exigiria um setor falso que passaria a restringir serviços. A zona vai
 * para tabela própria, como o 1º item do §3.7 pede.
 *
 *  1. cria `delivery_zones`;
 *  2. copia as áreas com `delivery_enabled = true` (mesmo dono, nome, regra e taxa);
 *  3. troca `pet_transports.service_area_id` por `delivery_zone_id`, apontando para a cópia;
 *  4. remove as colunas `delivery_*` de `service_areas`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('rule_type', 10);
            $table->json('ceps')->nullable();
            $table->decimal('max_km', 8, 2)->nullable();
            $table->decimal('fee', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'active']);
        });

        Schema::table('pet_transports', function (Blueprint $table): void {
            $table->foreignId('delivery_zone_id')->nullable()->after('appointment_id')->constrained()->nullOnDelete();
        });

        $this->copyZones();

        Schema::table('pet_transports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_area_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE service_areas DROP CONSTRAINT IF EXISTS service_areas_delivery_rule_check');
            DB::statement("ALTER TABLE delivery_zones ADD CONSTRAINT delivery_zones_rule_type_check CHECK (rule_type IN ('cep','distance'))");
            DB::statement('ALTER TABLE delivery_zones ADD CONSTRAINT delivery_zones_fee_check CHECK (fee >= 0)');
        }

        Schema::table('service_areas', function (Blueprint $table): void {
            $table->dropColumn(['delivery_enabled', 'delivery_rule_type', 'delivery_ceps', 'delivery_max_km', 'delivery_fee']);
        });
    }

    public function down(): void
    {
        Schema::table('service_areas', function (Blueprint $table): void {
            $table->boolean('delivery_enabled')->default(false);
            $table->string('delivery_rule_type', 10)->nullable();
            $table->json('delivery_ceps')->nullable();
            $table->decimal('delivery_max_km', 8, 2)->nullable();
            $table->decimal('delivery_fee', 12, 2)->nullable();
        });
        Schema::table('pet_transports', function (Blueprint $table): void {
            $table->foreignId('service_area_id')->nullable()->constrained()->nullOnDelete();
            $table->dropConstrainedForeignId('delivery_zone_id');
        });
        Schema::dropIfExists('delivery_zones');
    }

    private function copyZones(): void
    {
        $areas = DB::table('service_areas')->where('delivery_enabled', true)->whereNotNull('delivery_rule_type')->get();

        foreach ($areas as $area) {
            $zoneId = DB::table('delivery_zones')->insertGetId([
                'organization_id' => $area->organization_id,
                'professional_id' => $area->professional_id,
                'name' => $area->name,
                'rule_type' => $area->delivery_rule_type,
                'ceps' => $area->delivery_ceps,
                'max_km' => $area->delivery_max_km,
                'fee' => $area->delivery_fee ?? 0,
                'active' => (bool) $area->active && $area->deleted_at === null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('pet_transports')->where('service_area_id', $area->id)->update(['delivery_zone_id' => $zoneId]);
        }
    }
};
