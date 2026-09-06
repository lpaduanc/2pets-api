<?php

namespace Database\Factories;

use App\Models\Professional;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProfessionalFactory extends Factory
{
    protected $model = Professional::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->professional(),
            'professional_type' => 'vet',
            'business_name' => $this->faker->company(),
            'description' => $this->faker->sentence(),
            'specialties' => ['general'],
            'opening_hours' => '08:00',
            'closing_hours' => '18:00',
            'working_days' => [1, 2, 3, 4, 5],
            'service_radius_km' => 10,
            'services_offered' => ['consultation', 'vaccination'],
            'average_rating' => $this->faker->randomFloat(2, 3.0, 5.0),
            'total_reviews' => $this->faker->numberBetween(0, 100),
            'is_featured' => false,
        ];
    }

    /**
     * Mark the professional as featured.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    /**
     * Create a veterinarian type professional.
     */
    public function veterinarian(): static
    {
        return $this->state(fn (array $attributes) => [
            'professional_type' => 'vet',
            'crmv' => $this->faker->numerify('#####'),
            'crmv_state' => 'SP',
            'university' => $this->faker->company().' University',
            'graduation_year' => $this->faker->numberBetween(2000, 2023),
            'experience_years' => $this->faker->numberBetween(1, 20),
        ]);
    }

    /**
     * Create a clinic type professional.
     */
    public function clinic(): static
    {
        return $this->state(fn (array $attributes) => [
            'professional_type' => 'clinic',
        ]);
    }

    /**
     * Create a petshop type professional.
     */
    public function petshop(): static
    {
        return $this->state(fn (array $attributes) => [
            'professional_type' => 'petshop',
            'products_sold' => ['food', 'toys', 'accessories'],
        ]);
    }
}
