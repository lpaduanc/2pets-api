<?php

namespace Tests\Feature\Commercial;

use App\Models\Appointment;
use App\Models\AppointmentCharge;
use App\Models\AppointmentService;
use App\Models\MedicalRecord;
use App\Models\Sale;
use App\Services\Commercial\QuoteIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsQuoteFixtures;
use Tests\TestCase;

/**
 * Costuras do orçamento com o que já existia (doc 24): atendimento de origem, notificação à
 * clínica, linha do tempo do animal (doc 14), aba da ficha do animal e PDF.
 */
class QuoteFlowTest extends TestCase
{
    use BuildsQuoteFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_a_quote_generated_from_an_encounter_keeps_the_link_visible_on_both_sides(): void
    {
        $appointment = Appointment::create([
            'professional_id' => $this->owner->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '10:00',
            'type' => 'consultation',
            'status' => 'in_progress',
        ]);
        $consult = $this->makeService(['name' => 'Consulta', 'price' => 150, 'allow_price_override' => false]);
        $xray = $this->makeService(['name' => 'Raio-X', 'price' => 200]);
        // Preço combinado no agendamento diferente do de tabela: serviço que não permite
        // alteração cai no de tabela; o que permite mantém o combinado.
        AppointmentService::create(['appointment_id' => $appointment->id, 'service_id' => $consult->id, 'quantity' => 1, 'unit_price' => 120]);
        AppointmentCharge::create(['appointment_id' => $appointment->id, 'service_id' => $xray->id, 'description' => 'Raio-X de tórax', 'quantity' => 2, 'unit_price' => 180, 'added_by' => $this->owner->id]);
        AppointmentCharge::create(['appointment_id' => $appointment->id, 'service_id' => null, 'description' => 'Taxa avulsa', 'quantity' => 1, 'unit_price' => 10, 'added_by' => $this->owner->id]);
        $record = MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->owner->id,
            'appointment_id' => $appointment->id,
            'record_date' => now()->toDateString(),
            'status' => 'draft',
            'chief_complaint' => 'lameness',
        ]);

        $quote = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/quotes', ['medical_record_id' => $record->id])
            ->assertCreated()
            ->assertJsonPath('data.client_id', $this->tutor->id)
            ->assertJsonPath('data.pet_id', $this->pet->id)
            ->assertJsonPath('data.origin.medical_record_id', $record->id)
            ->assertJsonPath('data.origin.medical_record.chief_complaint', 'lameness')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.unit_price', 150)
            ->assertJsonPath('data.items.1.description', 'Raio-X de tórax')
            ->assertJsonPath('data.items.1.total', 360)
            ->assertJsonPath('data.total', 510)
            ->json('data');

        // Lado do atendimento: a lista filtrada pelo prontuário acha o orçamento.
        $this->getJson("/api/professional/quotes?medical_record_id={$record->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $quote['id']);

        // Quem não enxerga o prontuário não gera orçamento a partir dele.
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/quotes', ['medical_record_id' => $record->id])
            ->assertForbidden();
    }

    public function test_tutor_approval_in_the_app_is_seen_by_the_clinic_and_notifies_the_author(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();
        $this->sendQuote($id);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->tutor->id]);
        $tutorNotification = $this->tutor->notifications()->latest()->first();
        $this->assertSame("/tutor/orcamentos/{$id}", $tutorNotification->data['action_url']);

        $this->actingAs($this->tutor, 'sanctum')
            ->getJson('/api/me/quotes?pending=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.can_decide', true)
            ->assertJsonPath('data.0.clinic.name', $this->clinic->business_name);

        $this->getJson("/api/me/quotes/{$id}")->assertOk()->assertJsonPath('data.quote_status', 'viewed');
        $this->postJson("/api/me/quotes/{$id}/approve")->assertOk()->assertJsonPath('data.quote_status', 'approved');
        $this->getJson('/api/me/quotes?pending=1')->assertJsonCount(0, 'data');

        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson("/api/professional/quotes/{$id}")
            ->assertJsonPath('data.quote_status', 'approved')
            ->assertJsonPath('data.decision_channel', 'app')
            ->assertJsonPath('data.decided_by.id', $this->tutor->id);

        $clinicNotification = $this->receptionist->notifications()->latest()->first();
        $this->assertSame('quote_approved', $clinicNotification->data['type']);
        $this->assertSame("/professional/orcamentos/{$id}", $clinicNotification->data['action_url']);
    }

    public function test_the_quote_shows_up_in_the_pet_timeline_and_pet_tab_but_drafts_do_not_reach_the_tutor(): void
    {
        ['id' => $sent] = $this->draftQuoteWithThreeItems();
        $this->sendQuote($sent);
        ['id' => $draft] = $this->draftQuoteWithThreeItems();

        $timeline = collect($this->actingAs($this->tutor, 'sanctum')->getJson("/api/pets/{$this->pet->id}/timeline")->assertOk()->json('data'))
            ->where('type', 'quote');
        $this->assertSame([$sent], $timeline->pluck('id')->values()->all());
        $this->assertStringContainsString('R$ 300,00', $timeline->first()['summary']);

        $this->assertSame([$sent], collect($this->getJson("/api/pets/{$this->pet->id}/quotes")->assertOk()->json('data'))->pluck('id')->all());

        // A clínica vê os dois na aba do animal.
        $clinicIds = collect($this->actingAs($this->receptionist, 'sanctum')->getJson("/api/pets/{$this->pet->id}/quotes")->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$sent, $draft], $clinicIds->all());
    }

    public function test_another_clinic_cannot_see_the_quote(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();
        $outsider = \App\Models\User::factory()->professional()->create();

        $this->actingAs($outsider, 'sanctum')->getJson("/api/professional/quotes/{$id}")->assertNotFound();
        $this->assertSame([], $this->getJson("/api/pets/{$this->pet->id}/quotes")->json('data'));
    }

    public function test_the_pdf_carries_clinic_header_pet_items_total_and_validity(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();
        $this->sendQuote($id);
        $quote = Sale::with(['items', 'client', 'pet', 'createdBy'])->findOrFail($id);

        $html = view('pdfs.quote', [
            'quote' => $quote,
            'issuer' => QuoteIssuer::for($quote),
            'status' => $quote->effectiveQuoteStatus(),
        ])->render();

        foreach ([$this->clinic->business_name, $this->pet->name, 'Ração Renal', 'R$ 300,00', $quote->valid_until->format('d/m/Y'), 'Inclui retorno em 7 dias.'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringNotContainsString('interno', $html, 'internal notes never go to print');

        $response = $this->actingAs($this->receptionist, 'sanctum')->get("/api/professional/quotes/{$id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertNotNull($quote->pdf_path);
    }
}
