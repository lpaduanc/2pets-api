<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onda 3 da segmentação de cadastro (`docs/segmentacao-cadastro-profissional.md`): os 18
 * campos que `CompleteProfileProfessional.vue` já captura e o backend descartava (nenhum
 * estava em `rules()`, `$request->validated()` derruba tudo que não está lá), mais o gap de
 * "espécies/portes atendidos" que o `CLAUDE.md` promete e não existia em lugar nenhum.
 *
 * TODAS as colunas nascem `nullable()` — inclusive as obrigatórias na tela para alguns
 * tipos (mesma convenção de `2026_09_16_100000_...companies...`): a obrigatoriedade é regra
 * do Form Request (`ProfessionalCapabilityRegistry`), não do schema. Nenhuma tem `default`
 * boolean (nem `false`): profissional já cadastrado antes desta migration fica com `null`
 * ("nunca respondido"), distinto de `false` ("respondeu que não tem/não oferece") — decisão
 * deliberada, não temos como saber qual dos dois é verdade para quem já existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table): void {
            // Espécies/portes atendidos — alimenta o filtro de busca "Espécie atendida" que o
            // CLAUDE.md promete (V2 liga o filtro; aqui só nasce a coluna).
            $table->json('species_served')->nullable()->after('certifications');
            $table->json('sizes_served')->nullable()->after('species_served');

            // Estrutura/comodidade (§4.2) — nunca para `vet`.
            $table->boolean('parking_available')->nullable()->after('sizes_served');
            $table->boolean('wheelchair_accessible')->nullable();

            // Diferenciais por tipo (§4.3).
            $table->boolean('accepts_credit_card')->nullable();
            $table->boolean('accepts_pet_insurance')->nullable();
            $table->boolean('home_visit_available')->nullable();
            $table->boolean('online_consultation')->nullable();
            $table->boolean('emergency_available')->nullable();
            $table->boolean('emergency_24h')->nullable();
            $table->boolean('delivery_available')->nullable();
            $table->boolean('online_ordering')->nullable();
            $table->boolean('cage_free_option')->nullable();
            $table->boolean('webcam_access')->nullable();
            $table->boolean('special_diet_accommodation')->nullable();
            $table->boolean('mobile_service')->nullable();
            $table->boolean('group_sessions_available')->nullable();

            // Enriquecimento com tipo próprio (`ProfessionalAdditionalField`).
            $table->unsignedSmallInteger('exam_rooms_count')->nullable();
            $table->text('training_methodology')->nullable();
            $table->json('languages_spoken')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table): void {
            $table->dropColumn([
                'species_served', 'sizes_served',
                'parking_available', 'wheelchair_accessible',
                'accepts_credit_card', 'accepts_pet_insurance',
                'home_visit_available', 'online_consultation',
                'emergency_available', 'emergency_24h',
                'delivery_available', 'online_ordering',
                'cage_free_option', 'webcam_access', 'special_diet_accommodation',
                'mobile_service', 'group_sessions_available',
                'exam_rooms_count', 'training_methodology', 'languages_spoken',
            ]);
        });
    }
};
