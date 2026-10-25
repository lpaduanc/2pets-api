<?php

namespace Tests\Feature\Wallet;

use App\Models\Pet;
use App\Models\TutorExpense;
use App\Models\TutorRecurringExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TutorExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id, 'name' => 'Toddy']);
        Sanctum::actingAs($this->tutor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tutor_registers_expense_with_only_subcategory_and_amount(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');

        $response = $this->postJson('/api/wallet/expenses', ['subcategory' => 'dry_food', 'amount' => 189.9]);

        $response->assertCreated()
            ->assertJsonPath('data.category', 'food')
            ->assertJsonPath('data.description', 'Ração')
            ->assertJsonPath('data.spent_on', '2026-10-25')
            ->assertJsonPath('data.editable', true);
        $this->assertDatabaseHas('tutor_expenses', [
            'user_id' => $this->tutor->id,
            'category' => 'food',
            'subcategory' => 'dry_food',
            'amount' => 189.90,
        ]);
    }

    public function test_default_date_follows_sao_paulo_not_utc(): void
    {
        // 23h30 em São Paulo do dia 31 = 02h30 UTC do dia 1º. O gasto é de outubro.
        Carbon::setTestNow('2026-11-01 02:30:00');

        $this->postJson('/api/wallet/expenses', ['subcategory' => 'bath', 'amount' => 60])
            ->assertCreated()
            ->assertJsonPath('data.spent_on', '2026-10-31');
    }

    public function test_subcategory_from_another_category_is_rejected(): void
    {
        $this->postJson('/api/wallet/expenses', ['category' => 'food', 'subcategory' => 'vaccine', 'amount' => 90])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subcategory']);
    }

    public function test_amount_must_be_positive_and_reasonable(): void
    {
        $this->postJson('/api/wallet/expenses', ['subcategory' => 'toy', 'amount' => 0])
            ->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/wallet/expenses', ['subcategory' => 'toy', 'amount' => 1500000])
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_future_date_is_rejected(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');

        $this->postJson('/api/wallet/expenses', ['subcategory' => 'toy', 'amount' => 30, 'spent_on' => '2026-10-26'])
            ->assertJsonValidationErrors(['spent_on']);
    }

    public function test_pet_of_another_tutor_is_rejected(): void
    {
        $otherPet = Pet::factory()->create();

        $this->postJson('/api/wallet/expenses', ['subcategory' => 'toy', 'amount' => 30, 'pet_id' => $otherPet->id])
            ->assertJsonValidationErrors(['pet_id']);
    }

    public function test_repeat_option_creates_recurring_expense_linked_to_first_launch(): void
    {
        $response = $this->postJson('/api/wallet/expenses', [
            'subcategory' => 'dry_food',
            'amount' => 189.9,
            'pet_id' => $this->pet->id,
            'spent_on' => '2026-01-31',
            'repeat' => 'monthly',
        ]);

        $response->assertCreated()->assertJsonPath('data.source', 'recurring');
        $recurring = TutorRecurringExpense::sole();
        $this->assertSame('2026-02-28', $recurring->next_due_on->toDateString());
        $this->assertSame($recurring->id, TutorExpense::sole()->recurring_expense_id);
    }

    public function test_tutor_updates_and_deletes_own_expense(): void
    {
        $expense = TutorExpense::factory()->create(['user_id' => $this->tutor->id, 'amount' => 50]);

        $this->patchJson("/api/wallet/expenses/{$expense->id}", ['amount' => 75.5])
            ->assertOk()
            ->assertJsonPath('data.amount', 75.5);
        $this->deleteJson("/api/wallet/expenses/{$expense->id}")->assertOk();
        $this->assertDatabaseMissing('tutor_expenses', ['id' => $expense->id]);
    }

    public function test_expense_of_another_tutor_is_not_found(): void
    {
        $foreign = TutorExpense::factory()->create();

        $this->patchJson("/api/wallet/expenses/{$foreign->id}", ['amount' => 1])->assertNotFound();
        $this->deleteJson("/api/wallet/expenses/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/wallet/expenses/{$foreign->id}/repeat")->assertNotFound();
    }

    public function test_repeat_copies_expense_to_today(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        $expense = TutorExpense::factory()->create([
            'user_id' => $this->tutor->id,
            'subcategory' => 'bath',
            'category' => 'hygiene',
            'amount' => 60,
            'spent_on' => '2026-10-10',
        ]);

        $this->postJson("/api/wallet/expenses/{$expense->id}/repeat")
            ->assertCreated()
            ->assertJsonPath('data.spent_on', '2026-10-25')
            ->assertJsonPath('data.amount', 60);
        $this->assertSame(2, TutorExpense::count());
    }

    public function test_guest_cannot_use_wallet(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/wallet')->assertUnauthorized();
    }
}
