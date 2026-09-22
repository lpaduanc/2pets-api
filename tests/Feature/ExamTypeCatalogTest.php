<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Catálogo de exame da clínica — contrato docs/gap-simplesvet/specs/16-modelos-exame-laudos-spec.md.
 * Critério de aceite: cadastrar "Ultrassonografia abdominal" com apresentação e encerramento.
 */
class ExamTypeCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    protected function setUp(): void
    {
        parent::setUp();

        // `exam-types` (`exam-templates.view`/`.manage`) ganhou `permission:...` na rota sem
        // este teste seedar o catálogo — achado do agente clínico, 2026-09-21: o dono criado
        // abaixo herdava `vet_freelancer` da factory (`User::factory()->professional()`), mas
        // com `Role::findOrCreate()` em vez do papel seedado, então SEM permissão nenhuma.
        $this->seed(RolesAndPermissionsSeeder::class);

        $organization = Organization::factory()->create();
        $this->vet = User::factory()->professional()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $this->vet->id,
            'role' => OrganizationMember::ROLE_OWNER,
        ]);

        Sanctum::actingAs($this->vet);
    }

    public function test_creates_an_exam_type_with_presentation_and_closing(): void
    {
        $response = $this->postJson('/api/exam-types', [
            'name' => 'Ultrassonografia abdominal',
            'category' => 'imaging',
            'presentation_html' => '<p>Exame realizado em decúbito dorsal.</p>',
            'closing_html' => '<p>Sem outras alterações dignas de nota.</p>',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Ultrassonografia abdominal')
            ->assertJsonPath('data.category', 'imaging');

        $this->assertDatabaseHas('exam_types', ['name' => 'Ultrassonografia abdominal']);
    }

    public function test_rejects_a_category_outside_the_enum(): void
    {
        $response = $this->postJson('/api/exam-types', [
            'name' => 'Exame inválido',
            'category' => 'not-a-real-category',
        ]);

        $response->assertStatus(422);
    }

    public function test_a_freelancer_without_an_active_organization_cannot_create_a_catalog_entry(): void
    {
        $freelancer = User::factory()->professional()->create();
        Sanctum::actingAs($freelancer);

        $response = $this->postJson('/api/exam-types', [
            'name' => 'Hemograma',
            'category' => 'laboratory',
        ]);

        $response->assertStatus(422);
    }
}
