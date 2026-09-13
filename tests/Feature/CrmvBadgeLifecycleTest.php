<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regra crítica §2 do CLAUDE.md: o badge "verificado" existe enquanto houver aprovação manual
 * vigente do CRMV. `AdminController::verifyDocument()` liga `professionals.is_crmv_verified`,
 * mas `rejectDocument()` não desligava — um CRMV aprovado por engano e depois rejeitado seguia
 * exibindo "verificado" na busca (`ProfessionalSearchResource::verified`) e no perfil.
 */
class CrmvBadgeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $vet;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('vet_freelancer', 'web');

        $this->admin = User::factory()->create(['role' => 'admin', 'user_type' => 'tutor']);
        $this->admin->assignRole('admin');

        $this->vet = User::factory()->create(['role' => 'professional', 'user_type' => 'vet']);
        $this->vet->assignRole('vet_freelancer');

        Professional::create([
            'user_id' => $this->vet->id,
            'professional_type' => 'vet',
            'crmv' => 'SP-12345',
            'crmv_state' => 'SP',
        ]);
    }

    public function test_rejecting_a_crmv_document_clears_the_verified_badge(): void
    {
        $document = $this->crmvDocument();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/documents/{$document->id}/verify")->assertOk();

        $this->assertDatabaseHas('professionals', [
            'user_id' => $this->vet->id,
            'is_crmv_verified' => true,
        ]);

        $this->postJson("/api/admin/documents/{$document->id}/reject", [
            'notes' => 'CRMV ilegível no documento enviado.',
        ])->assertOk();

        $professional = Professional::where('user_id', $this->vet->id)->firstOrFail();

        $this->assertFalse((bool) $professional->is_crmv_verified, 'O badge deveria cair ao rejeitar o CRMV.');
        $this->assertNull($professional->crmv_verified_at);
        $this->assertNull($professional->crmv_verified_by);
    }

    /**
     * Rejeitar um documento que não é o CRMV (RG, diploma) não pode derrubar o badge — a
     * verificação do registro profissional é independente dos outros documentos.
     */
    public function test_rejecting_a_non_crmv_document_keeps_the_badge(): void
    {
        $crmv = $this->crmvDocument();
        $rg = $this->crmvDocument('rg');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/documents/{$crmv->id}/verify")->assertOk();

        $this->postJson("/api/admin/documents/{$rg->id}/reject", [
            'notes' => 'Foto cortada.',
        ])->assertOk();

        $this->assertDatabaseHas('professionals', [
            'user_id' => $this->vet->id,
            'is_crmv_verified' => true,
        ]);
    }

    private function crmvDocument(string $type = 'crmv'): Document
    {
        return Document::create([
            'user_id' => $this->vet->id,
            'document_type' => $type,
            'file_path' => "documents/{$type}.jpg",
            'file_name' => "{$type}.jpg",
            'original_name' => "{$type}.jpg",
            // `documents.file_type` guarda extensão curta ('pdf', 'jpg'...), nunca MIME type —
            // é o que `FileUploadService::upload()` grava de verdade. 'image/jpeg' cabia por
            // coincidência no varchar(10), mas violava o mesmo contrato que fazia
            // `CrmvVerificationTest` falhar com 'application/pdf' (15 chars, não cabe).
            'file_type' => 'jpg',
            'file_size' => 1024,
            'verification_status' => 'pending',
        ]);
    }
}
