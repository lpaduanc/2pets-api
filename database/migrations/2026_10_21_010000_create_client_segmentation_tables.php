<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`.
 *
 * `client_origins`/`churn_reasons` seguem o MESMO padrão de catálogo já estabelecido pelas
 * specs 13/14/15/16 (`immunization_products`, etc.): `organization_id` nullable = catálogo
 * global da plataforma (seedado, não editável via API — ver `OrganizationCatalogGate`). Vet
 * volante (sem organização) enxerga só o catálogo global, mesma regra de lá — não ganhou
 * `professional_id` próprio de propósito, para não reabrir uma decisão de produto já tomada.
 *
 * `client_relationship_profiles`/`client_segments` usam o par `organization_id` (nullable) +
 * `professional_id` do `CommercialScopeResolver` — nunca `company_id` (ver
 * `app/Services/Commercial/CommercialScopeResolver.php`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createOrganizationCatalog('client_origins');
        $this->createOrganizationCatalog('churn_reasons');
        $this->createTags();
        $this->createClientRelationshipProfiles();
        $this->createClientSegments();
        $this->seedGlobalClientOrigins();
    }

    private function createOrganizationCatalog(string $table): void
    {
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $blueprint->string('name');
            $blueprint->boolean('active')->default(true);
            $blueprint->timestamps();
            $blueprint->softDeletes();

            $blueprint->index(['organization_id', 'active']);
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Nome único por organização (ou global) entre os vivos — mesmo padrão de
        // `2026_10_05_..._create_product_groups_and_brands_table` (índice parcial, softDeletes
        // não pode bloquear a recriação do mesmo nome depois de excluído).
        Schema::getConnection()->statement(
            "CREATE UNIQUE INDEX {$table}_org_name_unique ON {$table} (organization_id, lower(name)) WHERE deleted_at IS NULL AND organization_id IS NOT NULL"
        );
        Schema::getConnection()->statement(
            "CREATE UNIQUE INDEX {$table}_global_name_unique ON {$table} (lower(name)) WHERE deleted_at IS NULL AND organization_id IS NULL"
        );
    }

    /**
     * `tags` só se liga a `User` no MVP (ver spec, Escopo agora item 7) — `taggables` já nasce
     * genérico para não precisar de migration nova quando pet/venda entrarem depois.
     */
    private function createTags(): void
    {
        Schema::create('tags', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $blueprint->foreignId('professional_id')->nullable()->constrained('users')->cascadeOnDelete();
            $blueprint->string('name');
            $blueprint->timestamps();

            $blueprint->index(['organization_id']);
            $blueprint->index(['professional_id']);
        });

        Schema::create('taggables', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $blueprint->morphs('taggable');
            $blueprint->timestamps();

            $blueprint->unique(['tag_id', 'taggable_type', 'taggable_id']);
        });
    }

    /**
     * Uma linha por par (escopo comercial, cliente) — regra de negócio 1 da spec: o mesmo
     * tutor pode ter classificações DIFERENTES em clínicas diferentes.
     */
    private function createClientRelationshipProfiles(): void
    {
        Schema::create('client_relationship_profiles', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $blueprint->foreignId('professional_id')->constrained('users')->cascadeOnDelete();
            $blueprint->foreignId('client_id')->constrained('users')->cascadeOnDelete();

            $blueprint->foreignId('client_origin_id')->nullable()->constrained('client_origins')->nullOnDelete();
            $blueprint->foreignId('churn_reason_id')->nullable()->constrained('churn_reasons')->nullOnDelete();

            $blueprint->timestamp('first_interaction_at')->nullable();
            $blueprint->timestamp('last_interaction_at')->nullable();

            $blueprint->decimal('total_spent_365d', 12, 2)->default(0);
            $blueprint->decimal('total_spent_90d', 12, 2)->default(0);
            $blueprint->decimal('total_spent_30d', 12, 2)->default(0);

            $blueprint->char('abc_class', 1)->nullable();
            $blueprint->unsignedInteger('abc_position')->nullable();

            $blueprint->string('lifecycle_stage', 30)->default('no_purchase_yet');

            $blueprint->timestamp('archived_at')->nullable();
            $blueprint->text('notes')->nullable();
            $blueprint->timestamp('recalculated_at')->nullable();

            $blueprint->timestamps();

            $blueprint->index(['organization_id', 'lifecycle_stage']);
            $blueprint->index(['professional_id', 'lifecycle_stage']);
            $blueprint->index(['organization_id', 'abc_class']);
            $blueprint->index(['professional_id', 'abc_class']);
            $blueprint->index('archived_at');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Um perfil por cliente DENTRO do escopo comercial — organização quando existe,
        // profissional quando é vet volante (mesma regra de unicidade condicional já usada
        // em `professional_clients_live_unique`).
        Schema::getConnection()->statement(
            'CREATE UNIQUE INDEX client_relationship_profiles_org_client_unique '.
            'ON client_relationship_profiles (organization_id, client_id) WHERE organization_id IS NOT NULL'
        );
        Schema::getConnection()->statement(
            'CREATE UNIQUE INDEX client_relationship_profiles_pro_client_unique '.
            'ON client_relationship_profiles (professional_id, client_id) WHERE organization_id IS NULL'
        );
    }

    private function createClientSegments(): void
    {
        Schema::create('client_segments', function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $blueprint->foreignId('professional_id')->constrained('users')->cascadeOnDelete();
            $blueprint->string('name');
            $blueprint->json('definition');
            $blueprint->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $blueprint->timestamps();

            $blueprint->index(['organization_id']);
            $blueprint->index(['professional_id']);
        });
    }

    /**
     * Seed próprio do catálogo global — mesmos nomes do SimplesVet mais "Busca no 2pets",
     * que é auto-preenchido pelo `ClientOriginResolver` (regra de negócio 5 da spec) e
     * também precisa existir como linha seedada para o filtro de segmento encontrá-la.
     */
    private function seedGlobalClientOrigins(): void
    {
        $names = [
            'Facebook', 'Fachada da loja', 'Google', 'Indicação de amigo',
            'Indicação de veterinário', 'Instagram', 'Panfleto', 'Rádio', 'Revista',
            'Busca no 2pets',
        ];

        $now = now();

        Schema::connection(null)->getConnection()->table('client_origins')->insert(
            array_map(fn (string $name): array => [
                'organization_id' => null,
                'name' => $name,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $names)
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('client_segments');
        Schema::dropIfExists('client_relationship_profiles');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('churn_reasons');
        Schema::dropIfExists('client_origins');
    }
};
