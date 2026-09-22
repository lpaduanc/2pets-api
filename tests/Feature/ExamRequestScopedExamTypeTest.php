<?php

namespace Tests\Feature;

use App\Enums\ExamTypeCategory;
use App\Models\ExamType;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IDOR cross-tenant (revisão de segurança, achado Médio 2) — mesma raiz do achado Alto de
 * `generated-documents`: `POST exam-requests` aceitava qualquer `exam_type_id` existente,
 * mesmo do catálogo de OUTRA organização, sem checar `ExamType::scopeVisibleTo()`.
 */
class ExamRequestScopedExamTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $professionalB;

    private Pet $pet;

    private ExamType $examTypeFromOrganizationA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        $this->professionalB = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($organizationB, 'organization')
            ->create(['user_id' => $this->professionalB->id]);

        $this->pet = Pet::factory()->create(['user_id' => $this->professionalB->id]);

        $this->examTypeFromOrganizationA = ExamType::create([
            'organization_id' => $organizationA->id,
            'name' => 'Hemograma exclusivo da clínica A',
            'category' => ExamTypeCategory::LABORATORY->value,
            'active' => true,
        ]);
    }

    public function test_cannot_attach_an_exam_type_from_another_organizations_catalog(): void
    {
        $response = $this->actingAs($this->professionalB, 'sanctum')->postJson('/api/exam-requests', [
            'pet_id' => $this->pet->id,
            'exam_type_ids' => [$this->examTypeFromOrganizationA->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['exam_type_ids.0']);

        $this->assertDatabaseMissing('exam_request_exam_type', [
            'exam_type_id' => $this->examTypeFromOrganizationA->id,
        ]);
    }

    public function test_can_attach_a_global_exam_type(): void
    {
        $globalExamType = ExamType::create([
            'organization_id' => null,
            'name' => 'Hemograma padrão da plataforma',
            'category' => ExamTypeCategory::LABORATORY->value,
            'active' => true,
        ]);

        $response = $this->actingAs($this->professionalB, 'sanctum')->postJson('/api/exam-requests', [
            'pet_id' => $this->pet->id,
            'exam_type_ids' => [$globalExamType->id],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('exam_request_exam_type', [
            'exam_type_id' => $globalExamType->id,
        ]);
    }
}
