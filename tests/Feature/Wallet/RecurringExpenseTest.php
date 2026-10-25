<?php

namespace Tests\Feature\Wallet;

use App\Models\TutorExpense;
use App\Models\TutorRecurringExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecurringExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        Sanctum::actingAs($this->tutor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_launches_due_expense_and_advances_next_date(): void
    {
        Carbon::setTestNow('2026-10-25 09:00:00');
        $recurring = TutorRecurringExpense::factory()->create(['user_id' => $this->tutor->id, 'next_due_on' => '2026-10-25']);

        $this->artisan('wallet:generate-recurring')->assertSuccessful();

        $this->assertDatabaseHas('tutor_expenses', [
            'recurring_expense_id' => $recurring->id,
            'spent_on' => '2026-10-25',
            'amount' => 189.90,
        ]);
        $this->assertSame('2026-11-25', $recurring->fresh()->next_due_on->toDateString());
    }

    public function test_running_twice_never_duplicates(): void
    {
        Carbon::setTestNow('2026-10-25 09:00:00');
        TutorRecurringExpense::factory()->create(['user_id' => $this->tutor->id, 'next_due_on' => '2026-10-25']);

        $this->artisan('wallet:generate-recurring');
        TutorRecurringExpense::query()->update(['next_due_on' => '2026-10-25']);
        $this->artisan('wallet:generate-recurring');

        $this->assertSame(1, TutorExpense::count());
    }

    public function test_missed_runs_are_caught_up(): void
    {
        Carbon::setTestNow('2026-10-25 09:00:00');
        TutorRecurringExpense::factory()->create(['user_id' => $this->tutor->id, 'next_due_on' => '2026-08-25']);

        $this->artisan('wallet:generate-recurring');

        $this->assertSame(
            ['2026-08-25', '2026-09-25', '2026-10-25'],
            TutorExpense::orderBy('spent_on')->pluck('spent_on')->map->toDateString()->all(),
        );
    }

    public function test_paused_recurring_is_skipped(): void
    {
        Carbon::setTestNow('2026-10-25 09:00:00');
        TutorRecurringExpense::factory()->create(['user_id' => $this->tutor->id, 'next_due_on' => '2026-10-01', 'active' => false]);

        $this->artisan('wallet:generate-recurring');

        $this->assertSame(0, TutorExpense::count());
    }

    public function test_tutor_pauses_changes_amount_and_ends_recurring(): void
    {
        Carbon::setTestNow('2026-10-25 09:00:00');
        $recurring = TutorRecurringExpense::factory()->create(['user_id' => $this->tutor->id, 'next_due_on' => '2026-11-01']);
        $launched = TutorExpense::factory()->create(['user_id' => $this->tutor->id, 'recurring_expense_id' => $recurring->id]);

        $this->getJson('/api/wallet/recurring')->assertOk()->assertJsonPath('data.0.frequency_label', 'Todo mês');
        $this->patchJson("/api/wallet/recurring/{$recurring->id}", ['amount' => 199.9, 'active' => false])
            ->assertOk()
            ->assertJsonPath('data.amount', 199.9)
            ->assertJsonPath('data.active', false);
        $this->deleteJson("/api/wallet/recurring/{$recurring->id}")->assertOk();

        // O gasto já lançado continua: aconteceu de verdade.
        $this->assertNull($launched->fresh()->recurring_expense_id);
    }

    public function test_recurring_of_another_tutor_is_not_found(): void
    {
        $foreign = TutorRecurringExpense::factory()->create();

        $this->patchJson("/api/wallet/recurring/{$foreign->id}", ['active' => false])->assertNotFound();
        $this->deleteJson("/api/wallet/recurring/{$foreign->id}")->assertNotFound();
    }
}
