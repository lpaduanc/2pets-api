<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrmvVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_approving_crmv_flips_professional_badge(): void
    {
        $vet = User::factory()->professional()->create();
        Professional::create([
            'user_id' => $vet->id,
            'crmv' => 'SP-12345',
            'crmv_state' => 'SP',
            'professional_type' => 'vet',
            'is_crmv_verified' => false,
        ]);

        $document = Document::create([
            'user_id' => $vet->id,
            'document_type' => 'crmv',
            'file_name' => 'crmv.pdf',
            'file_path' => 'uploads/crmv.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'original_name' => 'original.pdf',
            'verification_status' => 'pending',
        ]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/documents/{$document->id}/verify", [
            'notes' => 'CRMV conferido na base do CFMV.',
        ])->assertOk();

        $this->assertDatabaseHas('professionals', [
            'user_id' => $vet->id,
            'is_crmv_verified' => true,
        ]);

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'verification_status' => 'verified',
        ]);
    }

    public function test_non_crmv_document_approval_does_not_flip_crmv_badge(): void
    {
        $vet = User::factory()->professional()->create();
        Professional::create([
            'user_id' => $vet->id,
            'crmv' => 'SP-12345',
            'crmv_state' => 'SP',
            'professional_type' => 'vet',
            'is_crmv_verified' => false,
        ]);

        $document = Document::create([
            'user_id' => $vet->id,
            'document_type' => 'rg',
            'file_name' => 'rg.pdf',
            'file_path' => 'uploads/rg.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'original_name' => 'original.pdf',
            'verification_status' => 'pending',
        ]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/documents/{$document->id}/verify")->assertOk();

        $this->assertDatabaseHas('professionals', [
            'user_id' => $vet->id,
            'is_crmv_verified' => false,
        ]);
    }
}
