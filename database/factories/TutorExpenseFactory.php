<?php

namespace Database\Factories;

use App\Enums\Wallet\ExpenseSubcategory;
use App\Models\TutorExpense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TutorExpense> */
class TutorExpenseFactory extends Factory
{
    protected $model = TutorExpense::class;

    public function definition(): array
    {
        $subcategory = $this->faker->randomElement(ExpenseSubcategory::cases());

        return [
            'user_id' => User::factory()->tutor(),
            'pet_id' => null,
            'category' => $subcategory->category(),
            'subcategory' => $subcategory,
            'description' => null,
            'amount' => $this->faker->randomFloat(2, 10, 500),
            'spent_on' => now()->toDateString(),
            'payment_method' => null,
        ];
    }
}
