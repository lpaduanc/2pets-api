<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\DocumentTemplate;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regra de negócio 1 da spec docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md: um `document_template` de conteúdo
 * clínico (`kind = certificate`, etc.) só é emitido por quem pratica ato clínico
 * (`User::isVeterinarian()`). `consent_form`/`generic` não têm essa restrição.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class DocumentIssuerRoleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();

        $tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $tutor->id]);
    }

    public function test_veterinarian_can_issue_a_clinical_template(): void
    {
        $vet = $this->staffWithWriteAccess('vet_freelancer');
        $template = $this->clinicalTemplate();

        Sanctum::actingAs($vet);
        $response = $this->postJson('/api/professional/generated-documents', [
            'document_template_id' => $template->id,
            'pet_id' => $this->pet->id,
        ]);

        $response->assertStatus(201);
    }

    public function test_non_clinical_role_cannot_issue_a_clinical_template(): void
    {
        $owner = $this->staffWithWriteAccess('clinic_owner');
        $template = $this->clinicalTemplate();

        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/professional/generated-documents', [
            'document_template_id' => $template->id,
            'pet_id' => $this->pet->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_non_clinical_role_can_issue_an_administrative_template(): void
    {
        $owner = $this->staffWithWriteAccess('clinic_owner');
        $template = DocumentTemplate::create([
            'organization_id' => $this->organization->id,
            'name' => 'Termo de consentimento',
            'kind' => 'consent_form',
            'body_html' => '<p>Autorizo o procedimento em {{pet.nome}}.</p>',
        ]);

        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/professional/generated-documents', [
            'document_template_id' => $template->id,
            'pet_id' => $this->pet->id,
        ]);

        $response->assertStatus(201);
    }

    private function clinicalTemplate(): DocumentTemplate
    {
        return DocumentTemplate::create([
            'organization_id' => $this->organization->id,
            'name' => 'Atestado de vacinação',
            'kind' => 'certificate',
            'body_html' => '<p>Atesto que {{pet.nome}} foi vacinado.</p>',
        ]);
    }

    /**
     * Cria um membro da organização com acesso de ESCRITA ao pet — isola a checagem de
     * papel clínico feita por `DocumentIssuanceService` da checagem de acesso ao pet
     * (`AuthorizesPetAccess`), que é uma regra diferente e já teria seu próprio teste.
     */
    private function staffWithWriteAccess(string $spatieRole): User
    {
        $user = User::factory()->tutor()->create();
        $user->assignRole(Role::findOrCreate($spatieRole, 'web'));
        OrganizationMember::factory()->for($this->organization)->for($user, 'user')->create();

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $user->id,
            'granted_by' => $this->pet->user_id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        return $user;
    }
}
