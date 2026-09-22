<?php

namespace Tests\Feature;

use App\Models\MessageAutomation;
use App\Models\MessageDispatch;
use App\Models\MessageTemplate;
use App\Models\Pet;
use App\Models\ProfessionalClient;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `crm:run-automations` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`,
 * critério de aceite "automação vacina vencendo não dispara duas vezes no mesmo dia para o
 * mesmo pet". Teste escrito conforme a regra do projeto: NÃO executado via `artisan test`.
 */
class RunMessageAutomationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_vaccine_due_automation_dispatches_once_and_skips_on_second_run_same_day(): void
    {
        $professional = User::factory()->professional()->create();
        $client = User::factory()->tutor()->create(['consent_sms_transactional' => true]);
        $pet = Pet::factory()->create(['user_id' => $client->id]);
        ProfessionalClient::create(['professional_id' => $professional->id, 'client_id' => $client->id]);

        Vaccination::create([
            'pet_id' => $pet->id,
            'professional_id' => $professional->id,
            'vaccine_name' => 'V10',
            'application_date' => now()->subMonths(11),
            'next_dose_date' => now()->addDays(7),
        ]);

        $template = MessageTemplate::create([
            'organization_id' => null,
            'professional_id' => $professional->id,
            'name' => 'Vacina a vencer',
            'channel' => 'sms',
            'category' => 'transactional',
            'body' => 'Ola {{client_name}}, a vacina do {{pet_name}} vence em breve.',
        ]);

        MessageAutomation::create([
            'organization_id' => null,
            'professional_id' => $professional->id,
            'message_template_id' => $template->id,
            'trigger' => 'vaccine_due',
            'offset_days' => -7,
            'active' => true,
        ]);

        $this->artisan('crm:run-automations')->assertSuccessful();
        $this->artisan('crm:run-automations')->assertSuccessful();

        $this->assertSame(1, MessageDispatch::where('client_id', $client->id)->count());
    }

    public function test_inactive_automation_is_never_resolved(): void
    {
        $professional = User::factory()->professional()->create();
        $template = MessageTemplate::create([
            'organization_id' => null,
            'professional_id' => $professional->id,
            'name' => 'Vacina a vencer',
            'channel' => 'sms',
            'category' => 'transactional',
            'body' => 'Ola',
        ]);

        MessageAutomation::create([
            'organization_id' => null,
            'professional_id' => $professional->id,
            'message_template_id' => $template->id,
            'trigger' => 'vaccine_due',
            'offset_days' => -7,
            'active' => false,
        ]);

        $this->artisan('crm:run-automations')->assertSuccessful();

        $this->assertSame(0, MessageDispatch::count());
    }
}
