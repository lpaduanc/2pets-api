<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG 1 (E2E manual, 2026-09-13): `medical_records`, `invoices` e `services` não tinham
 * `deleted_at`. `DELETE /api/professional/invoices/{id}` (e os equivalentes de prontuário e
 * serviço) apagava a linha do banco de verdade, violando a regra 5 do CLAUDE.md ("soft delete
 * em tudo") — grave para `medical_records` por implicação de guarda de prontuário veterinário.
 */
class ProfessionalRecordsSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private User $tutor;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

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

        Sanctum::actingAs($this->professional);
    }

    public function test_deleting_an_invoice_keeps_the_row_in_the_database(): void
    {
        $invoice = $this->createInvoice();

        $this->deleteJson("/api/professional/invoices/{$invoice->id}")->assertOk();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertNotNull(DB::table('invoices')->where('id', $invoice->id)->value('deleted_at'));
    }

    public function test_a_deleted_invoice_disappears_from_the_listing(): void
    {
        $invoice = $this->createInvoice();
        $this->deleteJson("/api/professional/invoices/{$invoice->id}")->assertOk();

        $response = $this->getJson('/api/professional/invoices');

        $response->assertOk();
        $this->assertNotContains(
            $invoice->id,
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_deleting_a_medical_record_keeps_the_row_in_the_database(): void
    {
        $record = MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'diagnosis' => 'Otite',
        ]);

        $this->deleteJson("/api/professional/medical-records/{$record->id}")->assertOk();

        $this->assertDatabaseHas('medical_records', ['id' => $record->id]);
        $this->assertNotNull(DB::table('medical_records')->where('id', $record->id)->value('deleted_at'));
    }

    public function test_a_deleted_medical_record_disappears_from_the_listing(): void
    {
        $record = MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->professional->id,
            'record_date' => now()->toDateString(),
            'diagnosis' => 'Otite',
        ]);

        $this->deleteJson("/api/professional/medical-records/{$record->id}")->assertOk();

        $response = $this->getJson('/api/professional/medical-records');

        $response->assertOk();
        $this->assertNotContains(
            $record->id,
            collect($response->json('data'))->pluck('id')->all()
        );
    }

    public function test_deleting_a_service_keeps_the_row_in_the_database(): void
    {
        $service = Service::create([
            'professional_id' => $this->professional->id,
            'name' => 'Consulta de rotina',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 150,
            'active' => true,
        ]);

        $this->deleteJson("/api/professional/services/{$service->id}")->assertOk();

        $this->assertDatabaseHas('services', ['id' => $service->id]);
        $this->assertNotNull(DB::table('services')->where('id', $service->id)->value('deleted_at'));
    }

    public function test_a_deleted_service_disappears_from_the_listing(): void
    {
        $service = Service::create([
            'professional_id' => $this->professional->id,
            'name' => 'Consulta de rotina',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 150,
            'active' => true,
        ]);

        $this->deleteJson("/api/professional/services/{$service->id}")->assertOk();

        $response = $this->getJson('/api/professional/services');

        $response->assertOk();
        $this->assertNotContains(
            $service->id,
            collect($response->json())->pluck('id')->all()
        );
    }

    /**
     * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §4/§11 item 4:
     * `destroy` só é permitido enquanto `status = draft` (`InvoicePolicy::editDraft`).
     */
    private function createInvoice(): Invoice
    {
        return Invoice::create([
            'professional_id' => $this->professional->id,
            'client_id' => $this->tutor->id,
            'invoice_number' => 'INV-SOFTDEL01',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['description' => 'Consulta', 'quantity' => 1, 'unit_price' => 100, 'total' => 100],
            ],
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 0,
            'total' => 100,
            'status' => 'draft',
        ]);
    }
}
