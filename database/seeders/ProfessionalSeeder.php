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
                'zip_code' => '01310-100'
            ]
        );

        // Create professional profile
        Professional::firstOrCreate(
            ['user_id' => $professional->id],
            [
                'business_name' => 'Clínica Veterinária PetCare',
                'type' => 'clinic',
                'description' => 'Clínica veterinária completa com atendimento 24h, cirurgias, exames e internação.',
                'phone' => '(11) 3333-4444',
                'website' => 'https://petcare.com.br'
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
                'zip_code' => '01305-000'
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
