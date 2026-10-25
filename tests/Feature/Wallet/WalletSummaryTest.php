<?php

namespace Tests\Feature\Wallet;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Pet;
use App\Models\TutorExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private Pet $toddy;

    private Pet $max;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 15:00:00');
        $this->tutor = User::factory()->tutor()->create();
        $this->toddy = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Toddy']);
        $this->max = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Max']);
        Sanctum::actingAs($this->tutor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function expense(string $subcategory, string $category, float $amount, string $date, ?Pet $pet = null): TutorExpense
    {
        return TutorExpense::factory()->create([
            'user_id' => $this->tutor->id,
            'pet_id' => $pet?->id,
            'category' => $category,
            'subcategory' => $subcategory,
            'amount' => $amount,
            'spent_on' => $date,
        ]);
    }

    private function paidInvoice(float $total, string $paidOn, string $status = 'paid'): Invoice
    {
        $clinic = User::factory()->professional()->create(['name' => 'Clínica Vida']);
        $appointment = Appointment::create([
            'professional_id' => $clinic->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->max->id,
            'appointment_date' => $paidOn,
            'appointment_time' => '10:00:00',
        ]);

        return Invoice::create([
            'professional_id' => $clinic->id,
            'client_id' => $this->tutor->id,
            'appointment_id' => $appointment->id,
            'invoice_number' => 'INV-'.uniqid(),
            'issue_date' => $paidOn,
            'due_date' => $paidOn,
            'payment_date' => $status === 'paid' ? $paidOn : null,
            'items' => [['description' => 'Consulta', 'quantity' => 1, 'price' => $total]],
            'subtotal' => $total,
            'total' => $total,
            'status' => $status,
        ]);
    }

    public function test_month_summary_totals_groups_and_compares_with_same_days_of_previous_month(): void
    {
        $this->expense('dry_food', 'food', 200, '2026-10-02', $this->toddy);
        $this->expense('bath', 'hygiene', 60, '2026-10-20', $this->toddy);
        $this->expense('toy', 'accessories', 40, '2026-10-21');
        $this->expense('dry_food', 'food', 150, '2026-09-10', $this->toddy);
        // Depois do dia 25 de setembro: fora da janela de comparação (1–25).
        $this->expense('dry_food', 'food', 999, '2026-09-28', $this->toddy);

        $data = $this->getJson('/api/wallet?period=month')->assertOk()->json('data');

        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-25'], $data['period']);
        $this->assertEquals(300, $data['stats']['total']);
        $this->assertEquals(150, $data['stats']['previous_total']);
        $this->assertEquals(100, $data['stats']['change_percent']);
        $this->assertSame('food', $data['stats']['top_category']);
        $this->assertSame(['food', 'hygiene', 'accessories'], array_column($data['by_category'], 'category'));
        $this->assertSame(67, $data['by_category'][0]['percent']);
        $this->assertCount(3, $data['expenses']);
        $this->assertSame('2026-10-21', $data['expenses'][0]['spent_on']);
    }

    public function test_expense_without_pet_is_its_own_group_not_split(): void
    {
        $this->expense('dry_food', 'food', 100, '2026-10-02', $this->toddy);
        $this->expense('cat_litter', 'hygiene', 50, '2026-10-03');

        $byPet = $this->getJson('/api/wallet')->json('data.by_pet');

        $this->assertSame('Toddy', $byPet[0]['pet']['name']);
        $this->assertEquals(100, $byPet[0]['amount']);
        $this->assertNull($byPet[1]['pet']);
        $this->assertEquals(50, $byPet[1]['amount']);
    }

    public function test_paid_invoice_enters_wallet_read_only_and_pending_does_not(): void
    {
        $this->paidInvoice(250, '2026-10-12');
        $this->paidInvoice(400, '2026-10-13', 'pending');

        $data = $this->getJson('/api/wallet')->json('data');

        $this->assertEquals(250, $data['stats']['total']);
        $this->assertSame('invoice', $data['expenses'][0]['source']);
        $this->assertFalse($data['expenses'][0]['editable']);
        $this->assertSame('Atendimento · Clínica Vida', $data['expenses'][0]['description']);
        $this->assertSame('Max', $data['expenses'][0]['pet']['name']);
    }

    public function test_pet_filter_includes_invoices_of_that_pet_only(): void
    {
        $this->paidInvoice(250, '2026-10-12');
        $this->expense('dry_food', 'food', 100, '2026-10-02', $this->toddy);

        $this->assertEquals(250, $this->getJson("/api/wallet?pet_id={$this->max->id}")->json('data.stats.total'));
        $this->assertEquals(100, $this->getJson("/api/wallet?pet_id={$this->toddy->id}")->json('data.stats.total'));
    }

    public function test_other_tutor_data_never_appears(): void
    {
        TutorExpense::factory()->create(['amount' => 500, 'spent_on' => '2026-10-10']);

        $this->assertEquals(0, $this->getJson('/api/wallet')->json('data.stats.total'));
    }

    public function test_change_percent_is_null_without_previous_spending(): void
    {
        $this->expense('toy', 'accessories', 40, '2026-10-21');

        $this->assertNull($this->getJson('/api/wallet')->json('data.stats.change_percent'));
    }

    public function test_monthly_average_counts_from_first_month_with_spending(): void
    {
        $this->expense('dry_food', 'food', 300, '2026-09-05');
        $this->expense('dry_food', 'food', 300, '2026-10-05');

        $this->assertEquals(300, $this->getJson('/api/wallet?period=year')->json('data.stats.monthly_average'));
    }

    public function test_trend_has_six_months_ending_in_current_month(): void
    {
        $this->expense('dry_food', 'food', 120, '2026-08-05');

        $trend = $this->getJson('/api/wallet')->json('data.trend');

        $this->assertSame(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], array_column($trend, 'month'));
        $this->assertEquals(120, $trend[3]['amount']);
    }

    public function test_custom_period_requires_both_dates(): void
    {
        $this->getJson('/api/wallet?period=custom&from=2026-10-01')->assertJsonValidationErrors(['to']);
        $this->getJson('/api/wallet?period=custom&from=2026-10-01&to=2026-10-10')->assertOk();
    }
}
