<?php

namespace Tests\Feature;

use App\DataTransferObjects\Crm\CrmMessageRequest;
use App\Enums\MessageDispatchStatus;
use App\Models\MessageAutomation;
use App\Models\MessageDispatch;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Crm\CrmMessageDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `CrmMessageDispatcher` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`.
 * Cobre consentimento (regras 1/2), dedup diário (regra 3) e whitelist de placeholder
 * (critério de aceite "sem injeção"). Teste escrito conforme a regra do projeto: NÃO
 * executado via `artisan test`.
 */
class CrmMessageDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private CrmMessageDispatcher $dispatcher;

    private User $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = app(CrmMessageDispatcher::class);
        $this->professional = User::factory()->professional()->create();
    }

    public function test_sms_transactional_consent_does_not_authorize_sms_marketing(): void
    {
        $client = User::factory()->tutor()->create([
            'consent_sms_transactional' => true,
            'consent_sms_marketing' => false,
            'phone' => '11988887777',
        ]);

        $marketingTemplate = $this->template(['channel' => 'sms', 'category' => 'marketing']);
        $dispatch = $this->dispatcher->dispatch(new CrmMessageRequest($marketingTemplate, $client, null, []));

        $this->assertSame(MessageDispatchStatus::BLOCKED_BY_CONSENT, $dispatch->status);
    }

    public function test_whatsapp_marketing_optout_blocks_campaign_but_not_transactional(): void
    {
        $client = User::factory()->tutor()->create([
            'consent_whatsapp_transactional' => true,
            'consent_whatsapp_marketing' => false,
            'phone' => '11988887777',
        ]);

        $marketing = $this->dispatcher->dispatch(new CrmMessageRequest(
            $this->template(['channel' => 'whatsapp', 'category' => 'marketing']),
            $client,
            null,
            [],
        ));
        $transactional = $this->dispatcher->dispatch(new CrmMessageRequest(
            $this->template(['channel' => 'whatsapp', 'category' => 'transactional']),
            $client,
            null,
            [],
        ));

        $this->assertSame(MessageDispatchStatus::BLOCKED_BY_CONSENT, $marketing->status);
        $this->assertNotSame(MessageDispatchStatus::BLOCKED_BY_CONSENT, $transactional->status);
    }

    public function test_automation_does_not_dispatch_twice_same_day_for_same_client_and_pet(): void
    {
        $client = User::factory()->tutor()->create(['consent_sms_transactional' => true]);
        $automation = MessageAutomation::create([
            'organization_id' => null,
            'professional_id' => $this->professional->id,
            'message_template_id' => $this->template(['channel' => 'sms', 'category' => 'transactional'])->id,
            'trigger' => 'vaccine_due',
            'offset_days' => -7,
        ]);

        $request = new CrmMessageRequest($automation->template, $client, null, [], $automation);

        $first = $this->dispatcher->dispatch($request);
        $second = $this->dispatcher->dispatch($request);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, MessageDispatch::where('automation_id', $automation->id)->count());
    }

    public function test_unknown_placeholder_is_left_untouched_never_interpolated(): void
    {
        $client = User::factory()->tutor()->create(['consent_sms_transactional' => true]);
        $template = $this->template([
            'channel' => 'sms',
            'category' => 'transactional',
            'body' => 'Ola {{client_name}}, seu {{secret_admin_token}} está pronto.',
        ]);

        $dispatch = $this->dispatcher->dispatch(new CrmMessageRequest($template, $client, null, ['client_name' => 'Maria']));

        $this->assertStringContainsString('Ola Maria', $dispatch->body_rendered);
        $this->assertStringContainsString('{{secret_admin_token}}', $dispatch->body_rendered);
    }

    /** @param  array<string, mixed>  $overrides */
    private function template(array $overrides): MessageTemplate
    {
        return MessageTemplate::create(array_merge([
            'organization_id' => null,
            'professional_id' => $this->professional->id,
            'name' => 'Teste',
            'channel' => 'sms',
            'category' => 'transactional',
            'body' => 'Ola {{client_name}}.',
        ], $overrides));
    }
}
