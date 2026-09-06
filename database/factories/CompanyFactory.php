<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->company(),
            'company_name' => $this->faker->company(),
            'cnpj' => $this->faker->numerify('##.###.###/####-##'),
            'contact_name' => $this->faker->name(),
            'contact_position' => 'Gerente de RH',
            'phone' => $this->faker->phoneNumber(),
            'website' => $this->faker->url(),
            'employee_count' => '11-50',
            'benefit_type' => 'plano_completo',
            'notes' => $this->faker->sentence(),
        ];
    }
}
