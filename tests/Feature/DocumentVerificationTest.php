<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET public/documents/verify/{code}` — contrato docs/gap-simplesvet/specs/
 * 15-modelos-documento-receituario-assinatura-spec.md, regra de negócio 6: confirma só
 * "documento válido, emitido por X em Y para o animal Z", NUNCA diagnóstico/prescrição/dado
 * de saúde. Sem autenticação.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class DocumentVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_code_confirms_authenticity_without_leaking_clinical_content(): void
    {
        $document = $this->issueDocument('Atesto que Bella tem leishmaniose e faz tratamento X.');

        $response = $this->getJson("/api/public/documents/verify/{$document->verification_code}");

        $response->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.document_kind', 'certificate')
            ->assertJsonPath('data.pet_name', $document->pet->name);

        $body = $response->getContent();
        $this->assertStringNotContainsString('leishmaniose', $body);
        $this->assertStringNotContainsString('body_html', $body);
    }

    public function test_unknown_code_returns_invalid_without_a_404(): void
    {
        $response = $this->getJson('/api/public/documents/verify/DOES-NOT-EXIST');

        $response->assertOk()->assertJsonPath('data.valid', false);
    }

    public function test_endpoint_requires_no_authentication(): void
    {
        $document = $this->issueDocument('Atesto que o animal está apto.');

        // Nenhum Sanctum::actingAs() aqui de propósito — o endpoint é público.
        $response = $this->getJson("/api/public/documents/verify/{$document->verification_code}");

        $response->assertOk();
    }

    private function issueDocument(string $bodyHtml): GeneratedDocument
    {
        $vet = User::factory()->professional()->create();
        $tutor = User::factory()->tutor()->create();
        $pet = Pet::factory()->create(['user_id' => $tutor->id]);

        PetVetAccess::create([
            'pet_id' => $pet->id,
            'veterinarian_id' => $vet->id,
            'granted_by' => $tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        $template = DocumentTemplate::create([
            'name' => 'Atestado',
            'kind' => 'certificate',
            'body_html' => '<p>Placeholder</p>',
        ]);

        Sanctum::actingAs($vet);
        $response = $this->postJson('/api/professional/generated-documents', [
            'document_template_id' => $template->id,
            'pet_id' => $pet->id,
        ]);
        $response->assertStatus(201);

        $document = GeneratedDocument::findOrFail($response->json('data.id'));
        $document->forceFill(['body_html' => $bodyHtml])->save();

        return $document->fresh('pet');
    }
}
