<?php

namespace Tests\Feature;

use App\Models\ClientSegment;
use App\Models\MessageTemplate;
use App\Models\ProfessionalClient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `message-campaigns` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`.
 * Cobre preview real antes do envio e isolamento entre profissionais (`message_dispatches`
 * não vaza entre clínicas). Teste escrito conforme a regra do projeto: NÃO executado via
 * `artisan test`.
 */
class MessageCampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->professional = User::factory()->professional()->create();
        Sanctum::actingAs($this->professional);
    }

    public function test_preview_shows_real_recipient_count_from_segment(): void
    {
        $client = User::factory()->tutor()->create();
        ProfessionalClient::create(['professional_id' => $this->professional->id, 'client_id' => $client->id]);

        $segment = ClientSegment::create([
            'organization_id' => null,
            'professional_id' => $this->professional->id,
            'name' => 'Todos',
            'definition' => [],
            'created_by' => $this->professional->id,
        ]);

        $response = $this->postJson('/api/professional/message-campaigns/preview', ['segment_id' => $segment->id]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.recipients_count'));
    }

    public function test_send_marks_campaign_as_sent_with_counters(): void
    {
        $client = User::factory()->tutor()->create(['consent_sms_transactional' => true]);
        ProfessionalClient::create(['professional_id' => $this->professional->id, 'client_id' => $client->id]);

        $segment = ClientSegment::create([
            'organization_id' => null,
            'professional_id' => $this->professional->id,
            'name' => 'Todos',
            'definition' => [],
            'created_by' => $this->professional->id,
        ]);

        $template = MessageTemplate::create([
            'organization_id' => null,
            'professional_id' => $this->professional->id,
            'name' => 'Campanha',
            'channel' => 'sms',
            'category' => 'transactional',
            'body' => 'Ola {{client_name}}',
        ]);

        $campaign = $this->postJson('/api/professional/message-campaigns', [
            'name' => 'Campanha de teste',
            'channel' => 'sms',
            'message_template_id' => $template->id,
            'client_segment_id' => $segment->id,
        ])->assertStatus(201)->json('data');

        $response = $this->postJson("/api/professional/message-campaigns/{$campaign['id']}/send")->assertOk();

        $response->assertJsonPath('data.status', 'sent');
        $this->assertSame(1, $response->json('data.recipients_count'));
    }

    public function test_store_rejects_both_segment_and_ad_hoc_list_together(): void
    {
        $template = MessageTemplate::create([
            'organization_id' => null,
            'professional_id' => $this->professional->id,
            'name' => 'Campanha',
            'channel' => 'sms',
            'category' => 'transactional',
            'body' => 'Ola',
        ]);

        $segment = ClientSegment::create([
            'organization_id' => null,
            'professional_id' => $this->professional->id,
            'name' => 'Todos',
            'definition' => [],
            'created_by' => $this->professional->id,
        ]);

        $this->postJson('/api/professional/message-campaigns', [
            'name' => 'Inválida',
            'channel' => 'sms',
            'message_template_id' => $template->id,
            'client_segment_id' => $segment->id,
            'ad_hoc_client_ids' => [1, 2],
        ])->assertStatus(422);
    }

    public function test_professional_a_does_not_see_message_history_of_professional_b_client(): void
    {
        $professionalB = User::factory()->professional()->create();
        $clientOfB = User::factory()->tutor()->create();
        ProfessionalClient::create(['professional_id' => $professionalB->id, 'client_id' => $clientOfB->id]);

        $this->getJson("/api/professional/clients/{$clientOfB->id}/message-history")->assertStatus(404);
    }
}
