<?php

namespace Database\Factories;

use App\Models\Breed;
use Illuminate\Database\Eloquent\Factories\Factory;

class BreedFactory extends Factory
{
    protected $model = Breed::class;

    public function definition(): array
    {
        return [
            'species' => 'dog',
            'name' => $this->faker->unique()->word() . ' ' . $this->faker->word(),
            'size_category' => $this->faker->randomElement(['mini', 'small', 'medium', 'large', 'giant']),
            'life_expectancy_years' => $this->faker->numberBetween(8, 16),
        ];
    }

    /**
     * Create a dog breed.
     */
    public function dog(): static
    {
        return $this->state(fn (array $attributes) => [
            'species' => 'dog',
        ]);
    }

    /**
     * Create a cat breed.
     */
    public function cat(): static
    {
        return $this->state(fn (array $attributes) => [
            'species' => 'cat',
        ]);
    }
}
