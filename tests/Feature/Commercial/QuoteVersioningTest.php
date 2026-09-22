<?php

namespace Tests\Feature\Commercial;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsQuoteFixtures;
use Tests\TestCase;

/**
 * Critérios de aceite do doc 24: "Orçamento aprovado é imutável; alteração exige revisão" e
 * "Revisar orçamento aprovado cria versão 2 e mantém a versão 1 legível".
 */
class QuoteVersioningTest extends TestCase
{
    use BuildsQuoteFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_an_approved_quote_is_immutable_and_revising_it_creates_version_two(): void
    {
        ['id' => $v1] = $this->draftQuoteWithThreeItems();
        $this->sendQuote($v1);
        $this->actingAs($this->tutor, 'sanctum')->postJson("/api/me/quotes/{$v1}/approve")->assertOk();

        $extra = $this->makeService(['name' => 'Exame pré-operatório', 'price' => 150]);

        $this->actingAs($this->receptionist, 'sanctum');
        $this->postJson("/api/professional/quotes/{$v1}/items", ['sellable_type' => 'service', 'sellable_id' => $extra->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'quote_not_editable');
        $this->postJson("/api/professional/quotes/{$v1}/discount", ['discount_type' => 'percent', 'discount_value' => 10])
            ->assertStatus(422);
        $this->putJson("/api/professional/quotes/{$v1}", ['printed_notes' => 'mudou'])->assertStatus(422);

        $v2 = $this->postJson("/api/professional/quotes/{$v1}/revise")
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.quote_status', 'draft')
            ->assertJsonPath('data.parent_quote_id', $v1)
            ->assertJsonPath('data.root_quote_id', $v1)
            ->assertJsonPath('data.total', 300)
            ->json('data.id');

        $this->postJson("/api/professional/quotes/{$v2}/items", ['sellable_type' => 'service', 'sellable_id' => $extra->id])
            ->assertCreated()
            ->assertJsonPath('quote.total', 450);

        // v1 intacta e legível: mesmo status, mesmos valores, mesmos itens.
        $this->getJson("/api/professional/quotes/{$v1}")
            ->assertOk()
            ->assertJsonPath('data.quote_status', 'approved')
            ->assertJsonPath('data.total', 300)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.versions.0.version', 1)
            ->assertJsonPath('data.versions.1.version', 2);

        // O tutor compara as duas depois que a v2 é enviada.
        $this->sendQuote($v2);
        $this->actingAs($this->tutor, 'sanctum')
            ->getJson("/api/me/quotes/{$v2}")
            ->assertOk()
            ->assertJsonCount(2, 'data.versions')
            ->assertJsonPath('data.versions.0.total', 300)
            ->assertJsonPath('data.versions.1.total', 450)
            ->assertJsonCount(4, 'data.versions.1.items')
            ->assertJsonMissingPath('data.notes');
    }

    public function test_revising_a_pending_quote_supersedes_it_and_kills_its_link(): void
    {
        ['id' => $v1] = $this->draftQuoteWithThreeItems();
        $token = $this->sendQuote($v1);

        $v2 = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/quotes/{$v1}/revise")
            ->assertCreated()
            ->json('data.id');

        $this->assertSame('superseded', $this->quote($v1)->quote_status->value);

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/public/quotes/{$token}/decide", ['decision' => 'approve'])->assertStatus(410);

        $this->actingAs($this->tutor, 'sanctum')->postJson("/api/me/quotes/{$v1}/approve")->assertStatus(422);

        // Revisar a versão substituída é recusado: a revisão parte da mais recente.
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/professional/quotes/{$v1}/revise")
            ->assertStatus(422)
            ->assertJsonPath('code', 'quote_not_revisable');

        // Terceira versão a partir da segunda continua a numeração da família.
        $this->postJson("/api/professional/quotes/{$v2}/revise")->assertCreated()->assertJsonPath('data.version', 3);
    }

    public function test_a_sent_quote_can_no_longer_be_edited_in_place(): void
    {
        ['id' => $id] = $this->draftQuoteWithThreeItems();
        $this->sendQuote($id);

        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson("/api/professional/quotes/{$id}")
            ->assertJsonPath('data.is_editable', false)
            ->assertJsonPath('data.can_revise', true);

        $itemId = $this->quote($id)->items()->value('id');
        $this->deleteJson("/api/professional/quotes/{$id}/items/{$itemId}")->assertStatus(422);
    }
}
