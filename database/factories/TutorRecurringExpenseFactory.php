<?php

namespace Database\Factories;

use App\Enums\Wallet\ExpenseCategory;
use App\Enums\Wallet\ExpenseFrequency;
use App\Enums\Wallet\ExpenseSubcategory;
use App\Models\TutorRecurringExpense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TutorRecurringExpense> */
class TutorRecurringExpenseFactory extends Factory
{
    protected $model = TutorRecurringExpense::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->tutor(),
            'pet_id' => null,
            'category' => ExpenseCategory::FOOD,
            'subcategory' => ExpenseSubcategory::DRY_FOOD,
            'description' => null,
            'amount' => 189.90,
            'frequency' => ExpenseFrequency::MONTHLY,
            'next_due_on' => now()->toDateString(),
            'active' => true,
        ];
    }
}
