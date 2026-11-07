<?php

namespace Tests\Feature\Support;

use App\Models\CompanyClientAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Services\Commercial\Goals\BusinessDayCalendar;
use App\Support\BusinessClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Data de negócio (`BusinessClock`): às 22h30 de 06/11 em São Paulo o UTC já está em 07/11 01h30.
 * "Hoje" para vencimento, dias úteis, validação e atraso continua sendo 06/11.
 */
class BusinessClockTest extends TestCase
{
    use BuildsCounterFixtures;
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

    public function test_business_today_is_the_sao_paulo_date_while_utc_is_already_tomorrow(): void
    {
        Carbon::setTestNow('2026-11-07 01:30:00 UTC');

        $this->assertSame('2026-11-07', now()->toDateString());
        $this->assertSame('2026-11-06', BusinessClock::todayString());
        $this->assertSame('2026-11-06 00:00:00', BusinessClock::today()->toDateTimeString());
        $this->assertSame('UTC', BusinessClock::today()->getTimezone()->getName());
    }

    public function test_entry_due_today_is_not_overdue_in_the_model_nor_in_the_widget(): void
    {
        Carbon::setTestNow('2026-11-07 01:30:00 UTC');
        $rent = FinancialCategory::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Aluguel', 'nature' => 'expense', 'kind' => 'entry', 'active' => true]);
        $entry = FinancialEntry::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'financial_category_id' => $rent->id,
            'description' => 'Aluguel', 'nature' => 'expense', 'due_date' => '2026-11-06', 'accrual_date' => '2026-11-06', 'amount' => '100.00', 'net_amount' => '100.00',
            'status' => 'open', 'created_by' => $this->owner->id]);

        $this->assertFalse($entry->fresh()->isOverdue());
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/dashboard-widgets/data/payables_due')->assertOk()
            ->assertJsonPath('data.values.count', 1)->assertJsonPath('data.values.overdue_count', 0)
            ->assertJsonPath('data.values.items.0.overdue', false);
        $this->getJson('/api/professional/dashboard-widgets/data/top_supplies')->assertOk()->assertJsonPath('data.values.to', '2026-11-06');
    }

    public function test_business_days_do_not_count_today_as_elapsed(): void
    {
        Carbon::setTestNow('2026-11-07 01:30:00 UTC');
        $calendar = app(BusinessDayCalendar::class);

        // 06/11 (sexta) ainda é hoje: decorridos = os dias úteis antes de 06/11, como se fosse 06/11 cedo.
        $this->assertSame($calendar->forMonth($this->clinic, '2026-11', CarbonImmutable::parse('2026-11-06')), $calendar->forMonth($this->clinic, '2026-11'));
        $this->assertNotSame($calendar->forMonth($this->clinic, '2026-11', CarbonImmutable::parse('2026-11-07')), $calendar->forMonth($this->clinic, '2026-11'));
    }

    public function test_today_or_later_validation_accepts_the_sao_paulo_date(): void
    {
        Carbon::setTestNow('2026-11-07 01:30:00 UTC');

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/appointments', [
            'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id, 'appointment_date' => '2026-11-06', 'appointment_time' => '23:00', 'type' => 'grooming',
        ])->assertCreated();
        $this->postJson('/api/professional/appointments', [
            'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id, 'appointment_date' => '2026-11-05', 'appointment_time' => '23:00', 'type' => 'grooming',
        ])->assertUnprocessable()->assertJsonValidationErrors('appointment_date');
    }

    public function test_credit_installment_due_today_has_no_late_fees(): void
    {
        Carbon::setTestNow('2026-10-01 13:00:00 UTC');
        CompanyClientAccount::create(['organization_id' => $this->clinic->id, 'client_id' => $this->tutor->id, 'allow_credit_sale' => true, 'credit_limit' => '1000.00']);
        $this->openRegisterFor($this->receptionist);
        $saleId = $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $this->makeProduct(['price' => 300, 'controls_stock' => false])->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/complete", ['credit_installments' => 1, 'first_due_date' => '2026-11-06'])->assertOk();

        Carbon::setTestNow('2026-11-07 01:30:00 UTC');

        $this->getJson("/api/professional/clients/{$this->tutor->id}/credit-installments")->assertOk()->assertJsonPath('data.0.status', 'upcoming');
        $this->postJson("/api/professional/clients/{$this->tutor->id}/credit-payments/quote", [])->assertOk()
            ->assertJsonPath('data.date', '2026-11-06')
            ->assertJsonPath('data.totals.fees', '0.00')
            ->assertJsonPath('data.totals.total_due', '300.00');
    }
}
