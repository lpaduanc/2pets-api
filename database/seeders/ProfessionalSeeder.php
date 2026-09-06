<?php

namespace Database\Seeders;

use App\Models\Professional;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ProfessionalSeeder extends Seeder
{
    public function run(): void
    {
        // Create a professional user
        $professional = User::updateOrCreate(
            ['email' => 'vet@2pets.com'],
            [
                'name' => 'Dr. Carlos Veterinário',
                'password' => Hash::make('password'),
                'role' => 'professional',
                'user_type' => 'vet',
                'phone' => '(11) 99999-8888',
                'address' => 'Av. Paulista, 1000',
                'neighborhood' => 'Bela Vista',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip_code' => '01310-100',
                'latitude' => -23.561684,
                'longitude' => -46.655981,
                'profile_completed' => true,
                'registration_status' => 'approved',
                'email_verified' => true,
                'is_suspended' => false,
            ]
        );

        // Update location column for PostGIS spatial queries
        \Illuminate\Support\Facades\DB::statement('
            UPDATE users SET location = ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)::geography
            WHERE id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL AND location IS NULL
        ', [$professional->id]);

        // Create professional profile
        Professional::updateOrCreate(
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
        $company = User::updateOrCreate(
            ['email' => 'petshop@2pets.com'],
            [
                'name' => 'PetShop Premium',
                'password' => Hash::make('password'),
                'role' => 'professional',
                'user_type' => 'petshop',
                'phone' => '(11) 98888-7777',
                'address' => 'Rua Augusta, 500',
                'neighborhood' => 'Consolação',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip_code' => '01305-000',
                'latitude' => -23.556856,
                'longitude' => -46.660607,
                'profile_completed' => true,
                'registration_status' => 'approved',
                'email_verified' => true,
                'is_suspended' => false,
            ]
        );

        \Illuminate\Support\Facades\DB::statement('
            UPDATE users SET location = ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)::geography
            WHERE id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL AND location IS NULL
        ', [$company->id]);

        // Create petshop professional profile
        Professional::updateOrCreate(
            ['user_id' => $company->id],
            [
                'business_name' => 'PetShop Premium',
                'professional_type' => 'petshop',
                'description' => 'Pet shop completo com banho e tosa, produtos premium e atendimento veterinário.',
                'specialties' => ['banho_tosa', 'produtos', 'acessorios'],
                'services_offered' => ['banho', 'tosa', 'hidratacao', 'venda_produtos'],
                'opening_hours' => '09:00',
                'closing_hours' => '19:00',
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
                'service_radius_km' => 15,
                'average_rating' => 4.6,
                'total_reviews' => 89,
                'is_featured' => true,
            ]
        );

        // Create additional professionals for search testing
        $vet2 = User::updateOrCreate(
            ['email' => 'dra.ana@2pets.com'],
            [
                'name' => 'Dra. Ana Cardiologista',
                'password' => Hash::make('password'),
                'role' => 'professional',
                'user_type' => 'vet',
                'phone' => '(11) 97777-6666',
                'address' => 'Rua Oscar Freire, 300',
                'neighborhood' => 'Jardins',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip_code' => '01426-001',
                'latitude' => -23.564722,
                'longitude' => -46.671944,
                'profile_completed' => true,
                'registration_status' => 'approved',
                'email_verified' => true,
                'is_suspended' => false,
            ]
        );

        \Illuminate\Support\Facades\DB::statement('
            UPDATE users SET location = ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)::geography
            WHERE id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL AND location IS NULL
        ', [$vet2->id]);

        Professional::updateOrCreate(
            ['user_id' => $vet2->id],
            [
                'business_name' => 'Dra. Ana - Cardiologia Veterinária',
                'professional_type' => 'vet',
                'description' => 'Especialista em cardiologia veterinária com 15 anos de experiência. Atendimento domiciliar em São Paulo.',
                'crmv' => '67890-SP',
                'crmv_state' => 'SP',
                'specialties' => ['cardiologia', 'clinica_geral'],
                'services_offered' => ['consulta', 'eletrocardiograma', 'ecocardiograma'],
                'opening_hours' => '09:00',
                'closing_hours' => '18:00',
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                'service_radius_km' => 30,
                'experience_years' => 15,
                'average_rating' => 4.9,
                'total_reviews' => 234,
                'is_featured' => false,
            ]
        );

        $vet3 = User::updateOrCreate(
            ['email' => 'dr.marcos@2pets.com'],
            [
                'name' => 'Dr. Marcos Ortopedista',
                'password' => Hash::make('password'),
                'role' => 'professional',
                'user_type' => 'vet',
                'phone' => '(11) 96666-5555',
                'address' => 'Av. Brasil, 1500',
                'neighborhood' => 'Jardim América',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip_code' => '01430-001',
                'latitude' => -23.570000,
                'longitude' => -46.665000,
                'profile_completed' => true,
                'registration_status' => 'approved',
                'email_verified' => true,
                'is_suspended' => false,
            ]
        );

        \Illuminate\Support\Facades\DB::statement('
            UPDATE users SET location = ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)::geography
            WHERE id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL AND location IS NULL
        ', [$vet3->id]);

        Professional::updateOrCreate(
            ['user_id' => $vet3->id],
            [
                'business_name' => 'Dr. Marcos - Ortopedia Animal',
                'professional_type' => 'vet',
                'description' => 'Ortopedista veterinário especializado em cirurgias de coluna e articulações.',
                'crmv' => '45678-SP',
                'crmv_state' => 'SP',
                'specialties' => ['ortopedia', 'cirurgia'],
                'services_offered' => ['consulta', 'cirurgia', 'fisioterapia'],
                'opening_hours' => '08:00',
                'closing_hours' => '17:00',
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                'service_radius_km' => 20,
                'experience_years' => 12,
                'average_rating' => 4.7,
                'total_reviews' => 156,
                'is_featured' => false,
            ]
        );
    }
}
