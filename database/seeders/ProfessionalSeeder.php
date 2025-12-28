<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Professional;
use Illuminate\Support\Facades\Hash;

class ProfessionalSeeder extends Seeder
{
    public function run(): void
    {
        // Create a professional user
        $professional = User::firstOrCreate(
            ['email' => 'vet@2pets.com'],
            [
                'name' => 'Dr. Carlos Veterinário',
                'password' => Hash::make('password'),
                'role' => 'professional',
                'phone' => '(11) 99999-8888',
                'address' => 'Av. Paulista, 1000',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip_code' => '01310-100',
                'latitude' => -23.561684,
                'longitude' => -46.655981,
            ]
        );

        // Create professional profile
        Professional::firstOrCreate(
            ['user_id' => $professional->id],
            [
                'business_name' => 'Clínica Veterinária PetCare',
                'professional_type' => 'clinic',
                'description' => 'Clínica veterinária completa com atendimento 24h, cirurgias, exames e internação.',
                'crmv' => '12345-SP',
                'crmv_state' => 'SP',
                'specialties' => ['cirurgia', 'clínica_geral', 'emergência'],
                'services_offered' => ['consulta', 'cirurgia', 'exames', 'internação'],
                'opening_hours' => '08:00',
                'closing_hours' => '20:00',
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
                'service_radius_km' => 50,
                'average_rating' => 4.8,
                'total_reviews' => 127,
                'is_featured' => true,
            ]
        );

        // Create a company user
        $company = User::firstOrCreate(
            ['email' => 'petshop@2pets.com'],
            [
                'name' => 'PetShop Premium',
                'password' => Hash::make('password'),
                'role' => 'company',
                'phone' => '(11) 98888-7777',
                'address' => 'Rua Augusta, 500',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip_code' => '01305-000',
                'latitude' => -23.556856,
                'longitude' => -46.660607,
            ]
        );

        // Create company profile
        \App\Models\Company::firstOrCreate(
            ['user_id' => $company->id],
            [
                'company_name' => 'PetShop Premium Ltda',
                'contact_person' => 'João Silva',
                'phone' => '(11) 3444-5555'
            ]
        );
    }
}
