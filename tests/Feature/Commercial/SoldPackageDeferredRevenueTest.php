<?php

namespace Tests\Feature\Commercial;

use App\Models\Appointment;
use App\Models\ClientAccountEntry;
use App\Models\CommissionClawback;
use App\Models\CommissionRule;
use App\Models\CommissionSettlementItem;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\SoldPackage;
use App\Models\SoldPackageSession;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Receita diferida de pacote — docs/gap-petflow/PLANO.md, 2.7 (golden `pacote-01` em
 * `tests/Fixtures/golden/package.json`): sessões congeladas na venda, receita sessão a sessão na
 * DRE gerencial, comissão e rentabilidade pelo valor da sessão (com o gatilho da 2.6), saldo
 * vencido (crédito ao cliente ou receita), prorrogação com permissão e log, status derivado e
 * cancelamento coerente com as sessões já usadas.
 */
class SoldPackageDeferredRevenueTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private Service $bath;

    private Service $hydration;

    private ServicePackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->buildClinic();
        $this->bath = $this->makeService(['name' => 'Banho', 'price' => 60, 'direct_cost' => '6.50', 'commission_percent' => 0], by: $this->owner);
        $this->hydration = $this->makeService(['name' => 'Hidratação', 'price' => 40, 'commission_percent' => 0], by: $this->owner);
        $this->package = ServicePackage::create([
            'professional_id' => $this->owner->id, 'organization_id' => $this->clinic->id, 'name' => '4 banhos + hidratação',
            'price' => 230, 'validity_type' => 'days_from_sale', 'validity_days' => 60, 'active' => true,
        ]);
        $this->package->items()->create(['service_id' => $this->bath->id, 'quantity' => 4]);
        $this->package->items()->create(['service_id' => $this->hydration->id, 'quantity' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_selling_the_package_freezes_the_golden_sessions_and_the_preview_shows_the_discount(): void
    {
        $sold = $this->sellPackage();

        $this->assertSame(['49.29', '49.29', '49.28', '49.28', '32.86'], $this->sessionAmounts($sold));
        $this->show($sold)
            ->assertJsonPath('data.package_price', '230.00')
            ->assertJsonPath('data.list_price_total', '280.00')
            ->assertJsonPath('data.discount_amount', '50.00')
            ->assertJsonPath('data.deferred_amount', '230.00')
            ->assertJsonPath('data.used_amount', '0.00')
            ->assertJsonPath('data.lifecycle_status', 'new')
            ->assertJsonPath('data.items.0.sessions.0.amount', '49.29');

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/service-packages/preview', [
            'price' => 230, 'items' => [['service_id' => $this->bath->id, 'quantity' => 4], ['service_id' => $this->hydration->id, 'quantity' => 1]],
        ])->assertOk()
            ->assertJsonPath('data.discount_amount', '50.00')
            ->assertJsonPath('data.discount_percent', '17.86')
            ->assertJsonPath('data.items.0.sessions', ['49.29', '49.29', '49.28', '49.28'])
            ->assertJsonPath('data.items.1.package_total', '32.86');
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/service-packages/{$this->package->id}")->assertOk()
            ->assertJsonPath('data.pricing.discount_percent', '17.86');
    }

    public function test_consumption_recognizes_the_session_value_in_the_managerial_statement_not_the_sale(): void
    {
        $this->clinic->update(['effective_sales_tax_rate' => '6']);
        $sold = $this->sellPackage();

        // Outubro: venda do pacote, nenhuma sessão usada — nada de receita, tudo diferido.
        $october = $this->statement('2026-10-01', '2026-10-31')->json('data');
        $this->assertSame('0.00', collect($october['lines'])->firstWhere('key', 'gross_revenue')['total']);
        $this->assertSame(['opening' => '0.00', 'sold' => '230.00', 'recognized' => '0.00', 'expired_revenue' => '0.00', 'credited' => '0.00', 'closing' => '230.00'], $october['package_deferral']);

        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->consume($sold, $this->bath)->assertOk()->assertJsonPath('data.used_amount', '49.29');

        $lines = collect($this->statement('2026-11-01', '2026-11-30')->json('data.lines'))->keyBy('key');
        $this->assertSame('49.29', $lines['gross_revenue']['total']);
        // Imposto: 6% da linha (13,80) rateado pelas sessões → 2,96 nesta. Custo do banho 6,50.
        $this->assertSame('2.96', $lines['sales_taxes']['total']);
        $this->assertSame('6.50', $lines['cost_of_sales']['total']);
        $this->assertSame(['opening' => '230.00', 'sold' => '0.00', 'recognized' => '49.29', 'expired_revenue' => '0.00', 'credited' => '0.00', 'closing' => '180.71'],
            $this->statement('2026-11-01', '2026-11-30')->json('data.package_deferral'));
    }

    public function test_session_commission_and_profitability_use_the_session_value_and_the_package_line_does_not_commission(): void
    {
        CommissionRule::create(['organization_id' => $this->clinic->id, 'scope' => 'all', 'percent' => 10, 'calculation_base' => 'gross', 'trigger' => 'on_sale', 'active' => true]);
        $sold = $this->sellPackage(staffId: $this->groomerMember->id);

        // A linha do pacote (vendida pelo tosador) não entra: a comissão vai por sessão.
        $this->open()->assertJsonCount(0, 'data');

        $this->consume($sold, $this->bath, ['staff_id' => $this->groomerMember->id])->assertOk();
        $this->open()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sale_item_id', null)
            ->assertJsonPath('data.0.base_amount', 49.29)
            ->assertJsonPath('data.0.commission_amount', 4.93)
            ->assertJsonPath('data.0.trigger', 'on_sale')
            ->assertJsonPath('data.0.description', 'Banho · sessão 1 (pacote 4 banhos + hidratação)');

        $this->close()->assertCreated()->assertJsonPath('data.total_amount', 4.93)
            ->assertJsonPath('data.items.0.description', 'Banho · sessão 1 (pacote 4 banhos + hidratação)');
        $this->assertNotNull(CommissionSettlementItem::sole()->sold_package_session_id);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/sold-packages/{$sold->id}/profitability")->assertOk()
            ->assertJsonPath('data.sessions.0.revenue', '49.29')
            ->assertJsonPath('data.sessions.0.cost_amount', '6.50')
            ->assertJsonPath('data.sessions.0.commission_amount', '4.93')
            ->assertJsonPath('data.sessions.0.contribution_margin', '37.86')
            ->assertJsonPath('data.balance.deferred', '180.71');
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/sales/{$sold->sale_id}/profitability")->assertOk()
            ->assertJsonPath('data.totals.commission_amount', '0.00');
        $this->actingAs($this->receptionist, 'sanctum')->getJson("/api/professional/sold-packages/{$sold->id}/profitability")->assertForbidden();
    }

    public function test_on_execution_session_commission_waits_for_the_appointment_and_reverses_if_it_goes_back(): void
    {
        CommissionRule::create(['organization_id' => $this->clinic->id, 'scope' => 'all', 'percent' => 10, 'calculation_base' => 'gross', 'trigger' => 'on_execution', 'active' => true]);
        $sold = $this->sellPackage();
        $appointment = Appointment::create([
            'professional_id' => $this->owner->id, 'organization_id' => $this->clinic->id, 'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id,
            'appointment_date' => now()->toDateString(), 'appointment_time' => '09:00', 'type' => 'grooming', 'status' => 'in_progress', 'sold_package_id' => $sold->id,
        ]);

        $this->consume($sold, $this->bath, ['staff_id' => $this->groomerMember->id, 'appointment_id' => $appointment->id])->assertOk();
        $this->open()->assertJsonCount(0, 'data');

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'completed'])->assertOk();
        $this->open()->assertJsonCount(1, 'data')->assertJsonPath('data.0.trigger', 'on_execution');
        $this->close()->assertCreated();

        $appointment->fresh()->update(['status' => 'in_progress']);
        $this->assertSame('4.93', (string) CommissionClawback::sole()->amount);
        $this->assertNotNull(CommissionClawback::sole()->sold_package_session_id);
        $this->open()->assertJsonCount(0, 'data')->assertJsonPath('pending_deductions.total', '4.93');
    }

    public function test_expired_balance_becomes_client_credit_by_default_once(): void
    {
        $sold = $this->sellPackage();
        $this->consume($sold, $this->bath)->assertOk();

        Carbon::setTestNow('2026-12-10 10:00:00');
        $this->artisan('sold-packages:resolve-expired')->assertSuccessful();
        $this->artisan('sold-packages:resolve-expired')->assertSuccessful();

        $entry = ClientAccountEntry::sole();
        $this->assertSame(['package_credit', 'credit', '180.71', 'sold_package', $sold->id], [$entry->type->value, $entry->direction->value, (string) $entry->amount, $entry->reference_type, $entry->reference_id]);
        $this->assertSame(4, SoldPackageSession::where('status', 'credited')->count());
        $this->show($sold)->assertJsonPath('data.lifecycle_status', 'expired')
            ->assertJsonPath('data.expiration.resolution', 'client_credit')
            ->assertJsonPath('data.expiration.amount', '180.71');

        // Crédito ao cliente não é receita: nada de receita em dezembro, a diferida baixa como crédito.
        $december = $this->statement('2026-12-01', '2026-12-31')->json('data');
        $this->assertSame('0.00', collect($december['lines'])->firstWhere('key', 'gross_revenue')['total']);
        $this->assertSame('180.71', $december['package_deferral']['credited']);
        $this->assertSame('0.00', $december['package_deferral']['closing']);
    }

    public function test_expiration_revenue_policy_recognizes_the_balance_the_day_after_it_expires(): void
    {
        $this->actingAs($this->owner, 'sanctum')->putJson('/api/professional/package-settings', ['expired_package_policy' => 'expiration_revenue', 'package_expiring_soon_days' => 15])
            ->assertOk()->assertJsonPath('data.expired_package_policy', 'expiration_revenue')->assertJsonPath('data.package_expiring_soon_days', 15);
        $sold = $this->sellPackage();

        Carbon::setTestNow('2026-12-10 10:00:00');
        $this->artisan('sold-packages:resolve-expired')->assertSuccessful();

        $this->assertSame(0, ClientAccountEntry::count());
        $this->assertSame(5, SoldPackageSession::where('status', 'expired')->count());
        // Venceu em 04/12 (60 dias de 05/10): receita de expiração em dezembro.
        $this->assertSame('230.00', collect($this->statement('2026-12-01', '2026-12-31')->json('data.lines'))->firstWhere('key', 'gross_revenue')['total']);
    }

    public function test_extending_validity_needs_the_permission_logs_and_is_refused_after_resolution(): void
    {
        $sold = $this->sellPackage();

        $this->actingAs($this->receptionist, 'sanctum')->postJson("/api/professional/sold-packages/{$sold->id}/extend-validity", ['expires_at' => '2027-01-31', 'reason' => 'Viagem do tutor'])
            ->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sold-packages/{$sold->id}/extend-validity", ['expires_at' => '2026-11-01', 'reason' => 'Encurtar'])
            ->assertUnprocessable()->assertJsonValidationErrors('expires_at');
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sold-packages/{$sold->id}/extend-validity", ['expires_at' => '2027-01-31', 'reason' => 'Viagem do tutor'])
            ->assertOk()->assertJsonPath('data.expires_at', '2027-01-31');

        $this->show($sold)->assertJsonPath('validity_extensions.0.from_date', '2026-12-04')
            ->assertJsonPath('validity_extensions.0.to_date', '2027-01-31')
            ->assertJsonPath('validity_extensions.0.reason', 'Viagem do tutor')
            ->assertJsonPath('validity_extensions.0.user.id', $this->owner->id);

        Carbon::setTestNow('2027-02-05 10:00:00');
        $this->artisan('sold-packages:resolve-expired')->assertSuccessful();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sold-packages/{$sold->id}/extend-validity", ['expires_at' => '2027-03-31', 'reason' => 'Tarde demais'])
            ->assertUnprocessable();
    }

    public function test_derived_status_average_return_and_appointments(): void
    {
        $sold = $this->sellPackage();
        $this->show($sold)->assertJsonPath('data.lifecycle_status', 'new')->assertJsonPath('data.average_return_days', null);

        $this->consume($sold, $this->bath)->assertOk();
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->consume($sold, $this->bath)->assertOk();
        Carbon::setTestNow('2026-10-30 10:00:00');
        $this->consume($sold, $this->bath)->assertOk();
        $next = $this->bookSession($sold, '2026-11-06');
        // Sessão passada já atendida (a API só agenda de hoje em diante).
        Appointment::create([
            'professional_id' => $this->owner->id, 'organization_id' => $this->clinic->id, 'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id,
            'appointment_date' => '2026-10-20', 'appointment_time' => '09:00', 'type' => 'grooming', 'status' => 'completed', 'sold_package_id' => $sold->id,
        ]);

        $this->show($sold)->assertJsonPath('data.lifecycle_status', 'in_use')
            ->assertJsonPath('data.times_used', 3)
            ->assertJsonPath('data.average_return_days', 12.5)
            ->assertJsonPath('data.next_appointment.id', $next->id)
            ->assertJsonPath('data.last_appointment.starts_at', '2026-10-20T09:00:00+00:00');
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/sold-packages?lifecycle_status=in_use')->assertOk()->assertJsonCount(1, 'data');

        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->show($sold)->assertJsonPath('data.lifecycle_status', 'expiring_soon');

        $this->consume($sold, $this->bath)->assertOk();
        $this->consume($sold, $this->hydration)->assertOk();
        $this->show($sold)->assertJsonPath('data.lifecycle_status', 'completed')->assertJsonPath('data.deferred_amount', '0.00');
    }

    public function test_cancelling_the_package_sale_cancels_unused_sessions_and_reverses_session_commission(): void
    {
        CommissionRule::create(['organization_id' => $this->clinic->id, 'scope' => 'all', 'percent' => 10, 'calculation_base' => 'gross', 'trigger' => 'on_sale', 'active' => true]);
        $sold = $this->sellPackage();
        $this->consume($sold, $this->bath, ['staff_id' => $this->groomerMember->id])->assertOk();
        $this->close()->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$sold->sale_id}/cancel", ['reason' => 'Desistiu do pacote'])->assertOk();

        $this->assertSame(['cancelled' => 4, 'consumed' => 1], SoldPackageSession::query()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->map(fn ($c): int => (int) $c)->sortKeys()->all());
        $this->assertSame('Venda cancelada: Desistiu do pacote', CommissionClawback::sole()->reason);
        $this->assertSame('0.00', collect($this->statement('2026-10-01', '2026-10-31')->json('data.lines'))->firstWhere('key', 'gross_revenue')['total']);
        $this->show($sold)->assertJsonPath('data.lifecycle_status', 'cancelled');
    }

    public function test_migration_backfills_sessions_of_packages_sold_before_it(): void
    {
        $sold = $this->sellPackage();
        $this->consume($sold, $this->bath)->assertOk();
        $migration = require database_path('migrations/2026_11_20_100000_add_package_deferred_revenue.php');

        $migration->down();
        $migration->up();

        $this->assertSame(['49.29', '49.29', '49.28', '49.28', '32.86'], $this->sessionAmounts($sold));
        $this->assertSame(['consumed', 'pending', 'pending', 'pending', 'pending'], SoldPackageSession::orderBy('sold_package_item_id')->orderBy('sequence')->get()->map(fn ($s): string => $s->status->value)->all());
        $this->assertSame('230.00', (string) DB::table('sold_packages')->value('package_price'));
    }

    public function test_completing_a_package_appointment_consumes_one_session_once_and_reopening_undoes_it(): void
    {
        CommissionRule::create(['organization_id' => $this->clinic->id, 'scope' => 'all', 'percent' => 10, 'calculation_base' => 'gross', 'trigger' => 'on_sale', 'active' => true]);
        $sold = $this->sellPackage();
        $appointment = $this->packageAppointment($sold, $this->bath);

        $this->complete($appointment);
        $this->complete($appointment);

        $session = SoldPackageSession::where('status', 'consumed')->sole();
        $this->assertSame([$this->bath->id, $this->ownerMember->id, $appointment->id], [$session->soldPackageItem->service_id, $session->staff_id, $session->consumption->appointment_id]);
        $this->assertSame(1, (int) $sold->items()->sum('quantity_used'));

        // Comissão da sessão fechada para o dono (profissional do agendamento) e depois reaberta.
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/commission-settlements', [
            'staff_id' => $this->ownerMember->id, 'period_from' => now()->subMonth()->toDateString(), 'period_to' => now()->toDateString(), 'received_until' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.total_amount', 4.93);

        $appointment->fresh()->update(['status' => 'in_progress']);

        $this->assertSame(0, SoldPackageSession::where('status', 'consumed')->count());
        $this->assertSame(0, (int) $sold->items()->sum('quantity_used'));
        $this->assertSame(0, \App\Models\SoldPackageConsumption::count());
        $this->assertSame('4.93', (string) CommissionClawback::sole()->amount);

        $this->complete($appointment);
        $this->assertSame(1, SoldPackageSession::where('status', 'consumed')->count());
    }

    public function test_completing_without_balance_or_with_an_ambiguous_service_completes_without_consuming(): void
    {
        $sold = $this->sellPackage();
        $ambiguous = $this->packageAppointment($sold, null);
        $this->complete($ambiguous);
        $this->assertSame(0, SoldPackageSession::where('status', 'consumed')->count());

        $this->consume($sold, $this->hydration)->assertOk();
        $noBalance = $this->packageAppointment($sold, $this->hydration);
        $this->complete($noBalance);
        $this->assertSame('completed', $noBalance->fresh()->status);
        $this->assertSame(1, SoldPackageSession::where('status', 'consumed')->count());
    }

    private function packageAppointment(SoldPackage $sold, ?Service $service): Appointment
    {
        $appointment = Appointment::create([
            'professional_id' => $this->owner->id, 'organization_id' => $this->clinic->id, 'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id,
            'appointment_date' => now()->toDateString(), 'appointment_time' => '09:00', 'type' => 'grooming', 'status' => 'in_progress', 'sold_package_id' => $sold->id,
        ]);

        if ($service !== null) {
            \App\Models\AppointmentService::create(['appointment_id' => $appointment->id, 'service_id' => $service->id, 'quantity' => 1, 'unit_price' => $service->price]);
        }

        return $appointment;
    }

    private function complete(Appointment $appointment): void
    {
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/professional/appointments/{$appointment->id}", ['status' => 'completed'])->assertOk();
    }

    private function sellPackage(?int $staffId = null): SoldPackage
    {
        $this->openRegisterFor($this->receptionist);
        $saleId = $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/sales', [
            'kind' => 'sale', 'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'package', 'sellable_id' => $this->package->id, 'staff_id' => $staffId])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->receptionist, 'cash'), 'amount' => 230])
            ->assertCreated()->assertJsonPath('sale.status', 'paid');

        return SoldPackage::where('sale_id', $saleId)->firstOrFail();
    }

    /** @param  array<string, mixed>  $extra */
    private function consume(SoldPackage $sold, Service $service, array $extra = []): TestResponse
    {
        $itemId = $sold->items()->where('service_id', $service->id)->value('id');

        return $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sold-packages/{$sold->id}/consume", ['sold_package_item_id' => $itemId, 'quantity' => 1] + $extra);
    }

    private function bookSession(SoldPackage $sold, string $date): Appointment
    {
        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/appointments', [
            'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id, 'appointment_date' => $date, 'appointment_time' => '09:00',
            'type' => 'grooming', 'sold_package_id' => $sold->id,
        ])->assertCreated()->assertJsonPath('data.sold_package_id', $sold->id)->json('data.id');

        return Appointment::findOrFail($id);
    }

    private function show(SoldPackage $sold): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/sold-packages/{$sold->id}")->assertOk();
    }

    private function statement(string $from, string $to): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/reports/income-statement?regime=managerial&from={$from}&to={$to}")->assertOk();
    }

    private function open(): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/professional/commissions/open?staff_id={$this->groomerMember->id}&received_until=".now()->toDateString())->assertOk();
    }

    private function close(): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/commission-settlements', [
            'staff_id' => $this->groomerMember->id, 'period_from' => now()->subMonth()->toDateString(),
            'period_to' => now()->toDateString(), 'received_until' => now()->toDateString(),
        ]);
    }

    /** @return list<string> */
    private function sessionAmounts(SoldPackage $sold): array
    {
        return SoldPackageSession::where('sold_package_id', $sold->id)->orderBy('sold_package_item_id')->orderBy('sequence')->pluck('amount')->map(fn ($a): string => (string) $a)->all();
    }
}
