<?php

namespace Database\Factories;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PetFactory extends Factory
{
    protected $model = Pet::class;

    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->name,
            'species' => 'Dog',
            'breed' => 'Mixed',
            'birth_date' => $this->faker->date(),
            'gender' => $this->faker->randomElement(['male', 'female']),
            'weight' => $this->faker->randomFloat(2, 1, 50),
            'color' => 'Brown',
            'neutered' => $this->faker->boolean,
            'temperament' => ['Friendly'],
            'social_with' => ['Dogs'],
        ];
    }
}
