<?php

namespace Database\Factories;

use App\Enums\BudgetRange;
use App\Enums\EstimatedPetOwnersRange;
use App\Enums\IndustrySector;
use App\Enums\PreferredCommunicationChannel;
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
            'legal_representative_name' => $this->faker->name(),
            'legal_representative_cpf' => $this->faker->numerify('###########'),
            'legal_representative_birth_date' => $this->faker->dateTimeBetween('-60 years', '-18 years'),
            'legal_representative_phone' => $this->faker->phoneNumber(),
            'industry_sector' => $this->faker->randomElement(IndustrySector::cases()),
            'has_pet_policy' => $this->faker->boolean(),
            'estimated_pet_owners' => $this->faker->randomElement(EstimatedPetOwnersRange::cases()),
            'preferred_communication' => $this->faker->randomElement(PreferredCommunicationChannel::cases()),
            'budget_range' => $this->faker->randomElement(BudgetRange::cases()),
            'start_date_preference' => $this->faker->dateTimeBetween('now', '+6 months'),
            'interested_services' => $this->faker->randomElements(
                ['vet_consults', 'vaccines', 'emergency', 'lab_exams', 'grooming'],
                2
            ),
        ];
    }
}
