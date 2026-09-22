<?php

namespace Tests\Feature\Commercial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Commercial\Concerns\BuildsQuoteFixtures;
use Tests\TestCase;

/**
 * Critério de aceite do doc 24: "Orçamento com `valid_until` no passado aparece como expirado
 * SEM RODAR JOB, e não pode ser aprovado". Nenhum teste aqui chama comando ou scheduler — só
 * o relógio anda.
 */
class QuoteExpiryTest extends TestCase
{
    use BuildsQuoteFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_sent_quote_past_its_validity_reads_as_expired_everywhere_and_cannot_be_approved(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();
        $token = $this->sendQuote($id);

        Carbon::setTestNow(now()->addDays(11));

        // Coluna continua `sent`: é a leitura que deriva o vencido.
        $this->assertSame('sent', $this->quote($id)->quote_status->value);

        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson("/api/professional/quotes/{$id}")
            ->assertOk()
            ->assertJsonPath('data.quote_status', 'expired')
            ->assertJsonPath('data.is_expired', true)
            ->assertJsonPath('data.can_convert', false);

        // Filtro em SQL segue a mesma regra.
        $this->assertSame([$id], collect($this->getJson('/api/professional/quotes?status=expired')->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->getJson('/api/professional/quotes?status=sent')->json('data'));

        $this->actingAs($this->tutor, 'sanctum')
            ->postJson("/api/me/quotes/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('code', 'quote_expired');

        // O link público expira junto com a validade.
        $this->getJson("/api/public/quotes/{$token}")->assertStatus(410)->assertJsonPath('code', 'quote_expired');
        $this->postJson("/api/public/quotes/{$token}/decide", ['decision' => 'approve'])
            ->assertStatus(410)
            ->assertJsonPath('code', 'quote_expired');

        $this->assertNull($this->quote($id)->decided_at);
    }

    public function test_the_last_valid_day_still_accepts_approval(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();
        $this->sendQuote($id);

        Carbon::setTestNow(now()->addDays(10)->setTime(23, 30));

        $this->actingAs($this->tutor, 'sanctum')
            ->postJson("/api/me/quotes/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.quote_status', 'approved');
    }

    public function test_an_approved_quote_does_not_turn_expired_after_its_validity(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();
        $this->sendQuote($id);
        $this->actingAs($this->tutor, 'sanctum')->postJson("/api/me/quotes/{$id}/approve")->assertOk();

        Carbon::setTestNow(now()->addDays(30));

        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson("/api/professional/quotes/{$id}")
            ->assertJsonPath('data.quote_status', 'approved')
            ->assertJsonPath('data.can_convert', true);
    }

    public function test_sending_without_validity_applies_the_default(): void
    {
        $service = $this->makeService(['price' => 90]);
        $id = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/quotes', ['client_id' => $this->tutor->id])
            ->json('data.id');
        $this->postJson("/api/professional/quotes/{$id}/items", ['sellable_type' => 'service', 'sellable_id' => $service->id]);

        $this->postJson("/api/professional/quotes/{$id}/send")
            ->assertOk()
            ->assertJsonPath('data.valid_until', today()->addDays(15)->toDateString());
    }
}
