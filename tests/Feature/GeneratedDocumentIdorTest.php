<?php

namespace Tests\Feature;

use App\Enums\DocumentTemplateKind;
use App\Enums\VetAccessLevel;
use App\Models\DocumentTemplate;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IDOR cross-tenant (revisão de segurança, achado Alto) — `POST generated-documents` aceitava
 * qualquer `document_template_id` existente, mesmo de OUTRA organização, sem
 * `Gate::authorize('view', $template)` (`DocumentTemplatePolicy::view`). Roteiro exato da PoC
 * do relatório: profissional B, com acesso de escrita a um pet, tentando emitir documento com
 * o template PRIVADO da organização A.
 */
class GeneratedDocumentIdorTest extends TestCase
{
    use RefreshDatabase;

    private User $professionalB;

    private Pet $pet;

    private DocumentTemplate $templateFromOrganizationA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        $this->professionalB = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($organizationB, 'organization')
            ->create(['user_id' => $this->professionalB->id]);

        $tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->professionalB->id,
            'granted_by' => $tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        $this->templateFromOrganizationA = DocumentTemplate::create([
            'organization_id' => $organizationA->id,
            'name' => 'Termo exclusivo da clínica A',
            'kind' => DocumentTemplateKind::GENERIC->value,
            'body_html' => '<p>Conteúdo proprietário da clínica A.</p>',
            'requires_signature' => false,
            'active' => true,
        ]);
    }

    public function test_cannot_issue_a_document_using_another_organizations_private_template(): void
    {
        $response = $this->actingAs($this->professionalB, 'sanctum')->postJson('/api/generated-documents', [
            'document_template_id' => $this->templateFromOrganizationA->id,
            'pet_id' => $this->pet->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('generated_documents', [
            'document_template_id' => $this->templateFromOrganizationA->id,
        ]);
    }

    public function test_can_issue_a_document_using_a_global_template(): void
    {
        $globalTemplate = DocumentTemplate::create([
            'organization_id' => null,
            'name' => 'Termo genérico da plataforma',
            'kind' => DocumentTemplateKind::GENERIC->value,
            'body_html' => '<p>Termo padrão.</p>',
            'requires_signature' => false,
            'active' => true,
        ]);

        $response = $this->actingAs($this->professionalB, 'sanctum')->postJson('/api/generated-documents', [
            'document_template_id' => $globalTemplate->id,
            'pet_id' => $this->pet->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('generated_documents', [
            'document_template_id' => $globalTemplate->id,
            'pet_id' => $this->pet->id,
        ]);
    }
}
