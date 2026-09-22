<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Exam;
use App\Models\ExamImage;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Anexo de exame (`exam_images`, reaproveitada como `exam_attachments` da spec 16 — ver
 * contrato de API) segue a mesma regra de acesso de qualquer dado clínico: storage privado,
 * `PetVetAccess` nos dois sentidos. Critério de aceite: teste de IDOR dedicado.
 */
class ExamAttachmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    private User $tutor;

    private Pet $pet;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->vet = User::factory()->professional()->create();
        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        $this->exam = Exam::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->vet->id,
            'exam_type' => 'imaging',
            'exam_name' => 'Radiografia',
            'exam_date' => now()->toDateString(),
        ]);
    }

    public function test_uploads_a_pdf_attachment_and_the_owner_can_download_it(): void
    {
        Sanctum::actingAs($this->vet);

        // `FileUploadService` inspeciona o MIME real (finfo), não a extensão — o fake
        // precisa de bytes de PDF de verdade, não apenas o nome do arquivo.
        $pdfContent = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

        $upload = $this->postJson("/api/exams/{$this->exam->id}/images", [
            'images' => [
                ['file' => UploadedFile::fake()->createWithContent('laudo-externo.pdf', $pdfContent)],
            ],
        ]);
        $upload->assertStatus(201);
        $imageId = $upload->json('data.0.id');

        Sanctum::actingAs($this->tutor);
        $this->getJson("/api/exams/images/{$imageId}/download")->assertOk();
    }

    public function test_a_vet_without_pet_vet_access_cannot_download_the_attachment(): void
    {
        $image = ExamImage::create([
            'exam_id' => $this->exam->id,
            'uploader_id' => $this->vet->id,
            'disk' => 'local',
            'file_path' => 'exams/'.$this->exam->id.'/laudo.pdf',
            'file_name' => 'laudo.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
        ]);

        $stranger = User::factory()->professional()->create();
        Sanctum::actingAs($stranger);

        $this->getJson("/api/exams/images/{$image->id}/download")->assertStatus(403);
    }
}
