<?php

namespace Tests\Feature\Commercial;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\ConsentLog;
use App\Models\MedicalRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsQuoteFixtures;
use Tests\TestCase;

/**
 * Seção "Segurança" e critérios de aceite do doc 24 sobre o link sem login: funciona sem
 * login, registra IP e data, o token não funciona duas vezes e a página não expõe prontuário.
 */
class QuotePublicTokenSecurityTest extends TestCase
{
    use BuildsQuoteFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_approval_by_public_link_works_without_login_is_logged_and_works_only_once(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();
        $token = $this->sendQuote($id);

        // O banco nunca guarda o token em texto puro.
        $this->assertNotSame($token, $this->quote($id)->public_token_hash);
        $this->assertSame(hash('sha256', $token), $this->quote($id)->public_token_hash);

        $this->app['auth']->forgetGuards();

        $this->getJson("/api/public/quotes/{$token}")
            ->assertOk()
            ->assertJsonPath('data.total', 300)
            ->assertJsonPath('data.can_decide', true)
            ->assertJsonPath('data.clinic.name', $this->clinic->business_name);
        $this->assertSame('viewed', $this->quote($id)->quote_status->value);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('User-Agent', 'Celular do tutor')
            ->postJson("/api/public/quotes/{$token}/decide", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.quote_status', 'approved');

        $quote = $this->quote($id);
        $this->assertSame('public_link', $quote->decision_channel);
        $this->assertSame('203.0.113.7', $quote->decision_ip);
        $this->assertSame($this->tutor->id, $quote->decided_by);
        $this->assertNotNull($quote->decided_at);

        $log = ConsentLog::where('consent_key', "quote_approval:{$id}")->sole();
        $this->assertTrue($log->granted);
        $this->assertSame($this->tutor->id, $log->user_id);
        $this->assertSame('203.0.113.7', $log->ip_address);
        $this->assertSame('quote_public_link', $log->source);

        // Segunda vez: nem leitura nem decisão.
        $this->postJson("/api/public/quotes/{$token}/decide", ['decision' => 'reject'])
            ->assertStatus(410)
            ->assertJsonPath('code', 'quote_link_used');
        $this->getJson("/api/public/quotes/{$token}")->assertStatus(410);
        $this->assertSame('approved', $this->quote($id)->quote_status->value);
        $this->assertSame(1, ConsentLog::where('consent_key', "quote_approval:{$id}")->count());
    }

    public function test_the_public_payload_never_carries_clinical_data(): void
    {
        $appointment = Appointment::create([
            'professional_id' => $this->owner->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->toDateString(),
            'appointment_time' => '09:00',
            'type' => 'consultation',
            'status' => 'completed',
        ]);
        $surgery = $this->makeService(['name' => 'Mastectomia', 'price' => 2500]);
        AppointmentService::create(['appointment_id' => $appointment->id, 'service_id' => $surgery->id, 'quantity' => 1, 'unit_price' => 2500]);
        $record = MedicalRecord::create([
            'pet_id' => $this->pet->id,
            'professional_id' => $this->owner->id,
            'appointment_id' => $appointment->id,
            'record_date' => now()->toDateString(),
            'status' => 'finalized',
            'finalized_at' => now(),
            'finalized_by' => $this->owner->id,
            'chief_complaint' => 'other',
            'chief_complaint_notes' => 'Nódulo mamário',
            'diagnosis' => 'Carcinoma mamário grau II',
            'plan' => 'Mastectomia unilateral',
        ]);

        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/quotes', ['medical_record_id' => $record->id])
            ->assertCreated()
            ->json('data.id');
        $token = $this->sendQuote($id, $this->owner);

        $this->app['auth']->forgetGuards();
        $body = $this->getJson("/api/public/quotes/{$token}")->assertOk()->getContent();

        foreach (['Carcinoma', 'Nódulo', 'unilateral', 'medical_record', 'diagnosis', 'Tutor pediu', (string) $record->id.',"'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "public payload must not carry: {$leak}");
        }
        $this->assertStringContainsString('Mastectomia', $body, 'the quoted item itself is expected');
        $this->assertStringNotContainsString($this->tutor->email, $body);
    }

    public function test_invalid_tokens_are_404_and_resending_kills_the_previous_link(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();
        $first = $this->sendQuote($id);
        $second = $this->sendQuote($id);

        $this->assertNotSame($first, $second);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/public/quotes/'.str_repeat('a', 48))->assertNotFound();
        $this->getJson('/api/public/quotes/curto')->assertNotFound();
        $this->getJson("/api/public/quotes/{$first}")->assertNotFound();
        $this->getJson("/api/public/quotes/{$second}")->assertOk();
    }

    public function test_deciding_in_the_app_also_closes_the_public_link(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();
        $token = $this->sendQuote($id);

        $this->actingAs($this->tutor, 'sanctum')
            ->postJson("/api/me/quotes/{$id}/reject", ['reason' => 'Vou pensar'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/public/quotes/{$token}/decide", ['decision' => 'approve'])
            ->assertStatus(410);
        $this->assertSame('rejected', $this->quote($id)->quote_status->value);
    }

    public function test_another_tutor_cannot_see_or_decide_someone_elses_quote(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();
        $this->sendQuote($id);
        $stranger = \App\Models\User::factory()->tutor()->create();

        $this->actingAs($stranger, 'sanctum')->getJson("/api/me/quotes/{$id}")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->postJson("/api/me/quotes/{$id}/approve")->assertNotFound();
        $this->assertSame('sent', $this->quote($id)->quote_status->value);
    }
}
