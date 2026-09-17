<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Anexo de prontuário (item 9 do MVP) — disco privado, download por rota autorizada.
 *
 * Escrito, não executado via `artisan test` — validado manualmente via `curl` (upload,
 * download com URL assinada e stream) contra a API em execução.
 *
 * `UploadedFile::fake()->image()` só funciona com `.png` neste container (GD sem suporte a
 * JPEG — ver `.claude/agent-memory/backend-specialist/`), por isso o fake abaixo usa PNG.
 */
class MedicalRecordAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private User $tutor;

    private Pet $pet;

    private MedicalRecord $record;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->professional = User::factory()->professional()->create();
        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->professional->id,
            'granted_by' => $this->tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        $this->record = MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
    }

    public function test_author_can_upload_an_image_attachment(): void
    {
        Sanctum::actingAs($this->professional);

        $response = $this->postJson(
            "/api/professional/medical-records/{$this->record->id}/attachments",
            ['file' => UploadedFile::fake()->image('exame.png')]
        );

        $response->assertCreated()->assertJsonPath('data.original_name', 'exame.png');
        $this->assertDatabaseHas('medical_record_attachments', [
            'medical_record_id' => $this->record->id,
            'uploaded_by' => $this->professional->id,
        ]);

        // Nunca vaza o caminho interno do disco privado.
        $response->assertJsonMissingPath('data.path');
    }

    public function test_attachment_can_be_removed_while_the_record_is_a_draft(): void
    {
        Sanctum::actingAs($this->professional);
        $attachment = $this->record->attachments()->create([
            'path' => 'medical-records/1/1/fake.png',
            'original_name' => 'exame.png',
            'mime' => 'image/png',
            'size' => 10,
            'uploaded_by' => $this->professional->id,
        ]);

        $this->deleteJson("/api/professional/medical-records/{$this->record->id}/attachments/{$attachment->id}")
            ->assertOk();

        $this->assertSoftDeleted('medical_record_attachments', ['id' => $attachment->id]);
    }

    public function test_attachment_cannot_be_removed_once_the_record_is_finalized(): void
    {
        $this->record->update([
            'status' => 'finalized',
            'finalized_at' => now(),
            'finalized_by' => $this->professional->id,
            'weight' => 10,
            'diagnosis' => 'Otite',
        ]);
        $attachment = $this->record->attachments()->create([
            'path' => 'medical-records/1/1/fake.png',
            'original_name' => 'exame.png',
            'mime' => 'image/png',
            'size' => 10,
            'uploaded_by' => $this->professional->id,
        ]);

        Sanctum::actingAs($this->professional);

        $this->deleteJson("/api/professional/medical-records/{$this->record->id}/attachments/{$attachment->id}")
            ->assertForbidden();
    }

    public function test_tutor_can_download_an_attachment_of_a_finalized_record(): void
    {
        $this->record->update([
            'status' => 'finalized',
            'finalized_at' => now(),
            'finalized_by' => $this->professional->id,
            'weight' => 10,
            'diagnosis' => 'Otite',
        ]);
        $attachment = $this->record->attachments()->create([
            'path' => 'medical-records/1/1/fake.png',
            'original_name' => 'exame.png',
            'mime' => 'image/png',
            'size' => 10,
            'uploaded_by' => $this->professional->id,
        ]);
        Storage::disk('local')->put($attachment->path, 'conteudo-fake');

        Sanctum::actingAs($this->tutor);

        $this->getJson("/api/medical-records/{$this->record->id}/attachments/{$attachment->id}/download")
            ->assertOk();
    }

    public function test_a_stranger_cannot_download_an_attachment(): void
    {
        $this->record->update([
            'status' => 'finalized',
            'finalized_at' => now(),
            'finalized_by' => $this->professional->id,
            'weight' => 10,
            'diagnosis' => 'Otite',
        ]);
        $attachment = $this->record->attachments()->create([
            'path' => 'medical-records/1/1/fake.png',
            'original_name' => 'exame.png',
            'mime' => 'image/png',
            'size' => 10,
            'uploaded_by' => $this->professional->id,
        ]);

        $stranger = User::factory()->tutor()->create();
        Sanctum::actingAs($stranger);

        $this->getJson("/api/medical-records/{$this->record->id}/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }
}
