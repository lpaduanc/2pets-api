<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Create a tutor user with verified email and approved status.
     */
    public function tutor(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'tutor',
            'user_type' => 'tutor',
            'email_verified' => true,
            'profile_completed' => true,
            'registration_status' => 'approved',
            'is_suspended' => false,
        ]);
    }

    /**
     * Create a professional user (vet freelancer by default).
     */
    public function professional(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'professional',
            'user_type' => 'vet',
            'email_verified' => true,
            'profile_completed' => true,
            'registration_status' => 'approved',
            'is_suspended' => false,
        ]);
    }

    /**
     * Create a veterinarian user with proper role for vet access tests.
     */
    public function veterinarian(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'vet_freelancer',
            'user_type' => 'vet',
            'email_verified' => true,
            'profile_completed' => true,
            'registration_status' => 'approved',
            'is_suspended' => false,
        ]);
    }

    /**
     * Create a company user with pending registration status.
     */
    public function company(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'company',
            'user_type' => 'clinic',
            'email_verified' => true,
            'profile_completed' => false,
            'registration_status' => 'pending',
            'is_suspended' => false,
        ]);
    }

    /**
     * Create a suspended user.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_suspended' => true,
        ]);
    }

    /**
     * Create an admin user.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
            'user_type' => 'tutor',
            'email_verified' => true,
            'profile_completed' => true,
            'registration_status' => 'approved',
            'is_suspended' => false,
        ]);
    }
}
