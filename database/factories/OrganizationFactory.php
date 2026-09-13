<?php

namespace Database\Factories;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'organization_type' => OrganizationType::CLINIC->value,
            'business_name' => fake()->company(),
            'cnpj' => fake()->unique()->numerify(str_repeat('#', 14)),
            'description' => fake()->sentence(),
            'opening_hours' => '08:00',
            'closing_hours' => '18:00',
            'working_days' => [1, 2, 3, 4, 5],
            'service_radius_km' => null,
            'services_offered' => ['consultation'],
            'products_sold' => [],
            'address' => fake()->streetName(),
            'number' => (string) fake()->buildingNumber(),
            'neighborhood' => fake()->citySuffix(),
            'city' => fake()->city(),
            'state' => 'SP',
            'zip_code' => fake()->postcode(),
            'latitude' => fake()->latitude(-24, -23),
            'longitude' => fake()->longitude(-47, -46),
            'technical_responsible_verified' => false,
        ];
    }

    public function petshop(): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_type' => OrganizationType::PETSHOP->value,
            'products_sold' => ['food', 'toys'],
        ]);
    }
}
