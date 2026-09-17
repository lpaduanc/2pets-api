<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Invoice;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceTotalsTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $this->client = User::factory()->tutor()->create();

        // Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §12.4:
        // `POST /invoices` manual agora exige que `client_id` seja um cliente real deste
        // profissional (`ProfessionalClientsQuery`) — sem isso, todo `postJson` abaixo
        // devolveria 422 em `client_id`.
        $pet = Pet::factory()->create(['user_id' => $this->client->id]);
        PetVetAccess::create([
            'pet_id' => $pet->id,
            'veterinarian_id' => $this->professional->id,
            'granted_by' => $this->client->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);
    }

    /**
     * Bug: `InvoiceController::store` trusted whatever `total` the client sent, with no
     * cross-check against `items`. A payload with R$1450,00 in items and `total: 0.01` was
     * accepted and persisted as-is. The server must now always recompute `subtotal`/`total`
     * from `items` + `discount`/`tax`.
     */
    public function test_store_ignores_client_total_and_recomputes_from_items(): void
    {
        Sanctum::actingAs($this->professional);

        $response = $this->postJson('/api/professional/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['description' => 'Consulta', 'quantity' => 1, 'unit_price' => 1000],
                ['description' => 'Vacina', 'quantity' => 3, 'unit_price' => 150],
            ],
            'discount' => 0,
            'tax' => 0,
            'total' => 0.01,
            'status' => 'pending',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('invoices', [
            'client_id' => $this->client->id,
            'subtotal' => 1450.00,
            'total' => 1450.00,
        ]);
    }

    public function test_store_applies_discount_and_tax_on_top_of_item_totals(): void
    {
        Sanctum::actingAs($this->professional);

        $response = $this->postJson('/api/professional/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['description' => 'Consulta', 'quantity' => 2, 'unit_price' => 100],
            ],
            'discount' => 20,
            'tax' => 10,
            'status' => 'pending',
        ]);

        $response->assertStatus(201);

        // subtotal = 2 * 100 = 200; total = 200 + 10 (tax) - 20 (discount) = 190.
        $this->assertDatabaseHas('invoices', [
            'client_id' => $this->client->id,
            'subtotal' => 200.00,
            'total' => 190.00,
        ]);
    }

    public function test_store_rejects_item_without_required_fields(): void
    {
        Sanctum::actingAs($this->professional);

        $response = $this->postJson('/api/professional/invoices', [
            'client_id' => $this->client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'items' => [
                ['description' => 'Consulta'],
            ],
            'status' => 'pending',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity', 'items.0.unit_price']);
    }

    public function test_update_without_items_preserves_existing_items_and_recomputes_total(): void
    {
        Sanctum::actingAs($this->professional);

        // Contrato §4/§11 item 4: `update` só é permitido enquanto `status = draft`.
        $invoice = Invoice::create([
            'professional_id' => $this->professional->id,
            'client_id' => $this->client->id,
            'invoice_number' => 'INV-TEST0001',
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

        $response = $this->putJson("/api/professional/invoices/{$invoice->id}", [
            'discount' => 10,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'subtotal' => 100.00,
            'discount' => 10.00,
            'total' => 90.00,
        ]);
    }

    /**
     * Bug: `InvoiceResource` expunha só o objeto `client` aninhado (via `whenLoaded`),
     * nunca o `client_id` cru. O formulário de edição do app lê `invoice.client_id`
     * para pré-selecionar o cliente no select; sem o campo, o select abria vazio e a
     * própria validação do formulário barrava o reenvio — editar qualquer fatura
     * existente ficava impossível.
     */
    public function test_show_exposes_client_id_and_professional_id_as_raw_foreign_keys(): void
    {
        Sanctum::actingAs($this->professional);

        $invoice = Invoice::create([
            'professional_id' => $this->professional->id,
            'client_id' => $this->client->id,
            'invoice_number' => 'INV-TEST0002',
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

        $response = $this->getJson("/api/professional/invoices/{$invoice->id}");

        $response->assertOk()->assertJsonPath('data.client_id', $this->client->id)
            ->assertJsonPath('data.professional_id', $this->professional->id);
    }
}
