<?php

namespace Tests\Feature;

use App\Enums\DepositStatus;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use App\Models\Pet;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 6 do fluxo de agendamento: sinal (pagamento parcial antecipado), opcional e
 * desligado por padrão. Critério de aceite mais importante: com o sinal desligado, o
 * fluxo de confirmação é IDÊNTICO ao de antes desta fase.
 */
class AppointmentDepositTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private User $professional;

    private Appointment $appointment;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->professional = User::factory()->professional()->create();
        Professional::factory()->create(['user_id' => $this->professional->id]);

        $pet = Pet::factory()->create(['user_id' => $this->tutor->id]);
        $this->service = Service::create([
            'professional_id' => $this->professional->id,
            'name' => 'Consulta Geral',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 200.00,
            'active' => true,
        ]);

        $this->appointment = Appointment::create([
            'professional_id' => $this->professional->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $pet->id,
            'service_id' => $this->service->id,
            'appointment_date' => now()->addDay()->setTime(10, 0),
            'duration' => 30,
            'status' => 'pending',
            'booking_source' => 'client',
        ]);
    }

    // ---------------------------------------------------------------
    // Critério de aceite: caminho sem sinal não regride em nada
    // ---------------------------------------------------------------

    public function test_confirming_without_any_deposit_configured_behaves_exactly_as_before(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->professional);

        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/confirm");

        $response->assertOk()->assertJsonPath('data.status', 'confirmed');
        $response->assertJsonPath('data.deposit_status', 'none');
        $response->assertJsonPath('data.deposit_amount', null);

        $this->assertDatabaseHas('appointments', [
            'id' => $this->appointment->id,
            'status' => 'confirmed',
            'deposit_status' => 'none',
            'deposit_amount' => null,
        ]);

        // Nenhum Payment criado — nada de estado extra para um agendamento sem sinal.
        $this->assertDatabaseCount('payments', 0);

        // Só a notificação de confirmação de sempre — nenhuma notificação a mais.
        Notification::assertSentToTimes($this->tutor, InAppNotification::class, 1);
    }

    // ---------------------------------------------------------------
    // Resolução: estabelecimento, override por serviço, zero = desligado
    // ---------------------------------------------------------------

    public function test_deposit_configured_on_the_autonomous_professional_is_charged_on_confirm(): void
    {
        $this->professional->professional->update(['deposit_enabled' => true, 'deposit_percentage' => 10]);

        Http::fake(['api.mercadopago.com/*' => Http::response([
            'id' => 'mp-123', 'status' => 'pending',
        ], 201)]);

        Sanctum::actingAs($this->professional);
        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/confirm");

        $response->assertOk();
        $response->assertJsonPath('data.deposit_status', 'pending');
        $this->assertEquals(20.0, $response->json('data.deposit_amount')); // 10% de 200.00

        $this->assertDatabaseHas('appointments', [
            'id' => $this->appointment->id,
            'deposit_status' => 'pending',
            'deposit_amount' => 20.00,
        ]);

        $this->assertDatabaseHas('payments', [
            'appointment_id' => $this->appointment->id,
            'invoice_id' => null,
            'purpose' => 'deposit',
            'amount' => 20.00,
            'gateway_payment_id' => 'mp-123',
        ]);
    }

    public function test_service_override_wins_over_the_establishment_default(): void
    {
        $this->professional->professional->update(['deposit_enabled' => true, 'deposit_percentage' => 10]);
        $this->service->update(['deposit_enabled' => true, 'deposit_percentage' => 25]);

        Http::fake(['api.mercadopago.com/*' => Http::response(['id' => 'mp-override', 'status' => 'pending'], 201)]);

        Sanctum::actingAs($this->professional);
        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/confirm");

        $this->assertEquals(50.0, $response->json('data.deposit_amount')); // 25% de 200.00, não 10%
    }

    public function test_service_override_can_disable_deposit_even_when_establishment_has_it_enabled(): void
    {
        $this->professional->professional->update(['deposit_enabled' => true, 'deposit_percentage' => 10]);
        $this->service->update(['deposit_enabled' => false]);

        Sanctum::actingAs($this->professional);
        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/confirm");

        $response->assertJsonPath('data.deposit_status', 'none');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_zero_percent_is_equivalent_to_disabled(): void
    {
        $this->professional->professional->update(['deposit_enabled' => true, 'deposit_percentage' => 0]);

        Sanctum::actingAs($this->professional);
        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/confirm");

        $response->assertJsonPath('data.deposit_status', 'none');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_deposit_configured_on_the_organization_is_charged_on_confirm(): void
    {
        $organization = Organization::factory()->create(['deposit_enabled' => true, 'deposit_percentage' => 15]);
        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $this->professional->id,
        ]);
        $this->service->update(['organization_id' => $organization->id]);

        Http::fake(['api.mercadopago.com/*' => Http::response(['id' => 'mp-org', 'status' => 'pending'], 201)]);

        Sanctum::actingAs($this->professional);
        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/confirm");

        $this->assertEquals(30.0, $response->json('data.deposit_amount')); // 15% de 200.00
    }

    public function test_amount_is_rounded_to_two_decimal_places(): void
    {
        $this->service->update(['price' => 99.99]);
        $this->professional->professional->update(['deposit_enabled' => true, 'deposit_percentage' => 33]);

        Http::fake(['api.mercadopago.com/*' => Http::response(['id' => 'mp-round', 'status' => 'pending'], 201)]);

        Sanctum::actingAs($this->professional);
        $response = $this->postJson("/api/professional/appointments/{$this->appointment->id}/confirm");

        // 99.99 * 33 / 100 = 32.9967 -> arredonda para 33.00
        $this->assertEquals(33.0, $response->json('data.deposit_amount'));
    }

    // ---------------------------------------------------------------
    // Configuração — endpoints + autorização
    // ---------------------------------------------------------------

    public function test_autonomous_professional_reads_and_writes_own_deposit_settings(): void
    {
        Sanctum::actingAs($this->professional);

        $this->getJson('/api/professional/deposit-settings')
            ->assertOk()
            ->assertJson(['deposit_enabled' => false, 'deposit_percentage' => null]);

        $this->putJson('/api/professional/deposit-settings', ['deposit_enabled' => true, 'deposit_percentage' => 20])
            ->assertOk()
            ->assertJson(['deposit_enabled' => true, 'deposit_percentage' => 20.0]);

        $this->assertDatabaseHas('professionals', [
            'user_id' => $this->professional->id,
            'deposit_enabled' => true,
            'deposit_percentage' => 20.00,
        ]);
    }

    public function test_organization_owner_configures_deposit_settings(): void
    {
        $owner = User::factory()->professional()->create();
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);

        Sanctum::actingAs($owner);

        $this->putJson('/api/professional/deposit-settings', ['deposit_enabled' => true, 'deposit_percentage' => 12])
            ->assertOk();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'deposit_enabled' => true,
            'deposit_percentage' => 12.00,
        ]);
    }

    public function test_non_owner_team_member_cannot_write_deposit_settings(): void
    {
        $owner = User::factory()->professional()->create();
        $organization = Organization::factory()->create();
        OrganizationMember::factory()->owner()->create(['organization_id' => $organization->id, 'user_id' => $owner->id]);

        $member = User::factory()->professional()->create();
        OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $member->id]);

        Sanctum::actingAs($member);

        $this->putJson('/api/professional/deposit-settings', ['deposit_enabled' => true, 'deposit_percentage' => 10])
            ->assertStatus(403);
    }

    public function test_percentage_above_fifty_is_rejected(): void
    {
        Sanctum::actingAs($this->professional);

        $this->putJson('/api/professional/deposit-settings', ['deposit_enabled' => true, 'deposit_percentage' => 60])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------
    // Webhook confirma o pagamento do sinal
    // ---------------------------------------------------------------

    public function test_webhook_marks_deposit_as_paid_and_notifies_the_professional(): void
    {
        $this->appointment->update(['deposit_amount' => 20.00, 'deposit_status' => DepositStatus::PENDING->value]);
        $payment = Payment::create([
            'appointment_id' => $this->appointment->id,
            'user_id' => $this->tutor->id,
            'gateway' => 'mercadopago',
            'gateway_payment_id' => 'mp-webhook-1',
            'purpose' => 'deposit',
            'method' => 'pix',
            'amount' => 20.00,
            'status' => 'pending',
        ]);

        Http::fake(['api.mercadopago.com/*' => Http::response(['status' => 'approved'], 200)]);
        Notification::fake();

        $webhook = PaymentWebhook::create([
            'gateway' => 'mercadopago',
            'event_type' => 'payment',
            'gateway_payment_id' => 'mp-webhook-1',
            'payload' => ['data' => ['id' => 'mp-webhook-1']],
        ]);

        (new ProcessPaymentWebhook($webhook->id))->handle(
            app(\App\Services\Payment\PaymentService::class),
            app(\App\Services\Appointment\AppointmentDepositService::class),
        );

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(DepositStatus::PAID->value, $this->appointment->fresh()->deposit_status);

        Notification::assertSentTo($this->professional, InAppNotification::class);
    }
}
