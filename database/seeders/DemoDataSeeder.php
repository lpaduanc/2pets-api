<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\Professional;
use App\Models\User;
use App\Models\Vaccination;
use Database\Seeders\Demo\SearchableDemoProfessional;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed demo data for development/testing.
     * Creates a tutor with pets, a professional with appointments,
     * and sample data across the system.
     */
    public function run(): void
    {
        // Endereco de demonstracao em SP (regiao beta do MVP). `latitude`/`longitude`
        // sao obrigatorios: o model User sincroniza a coluna PostGIS `location` a partir
        // deles a cada save(), e sem `location` o usuario nao existe para `ST_DWithin`.
        $demoAddress = [
            'address' => 'Avenida Paulista',
            'number' => '1578',
            'neighborhood' => 'Bela Vista',
            'city' => 'Sao Paulo',
            'state' => 'SP',
            'zip_code' => '01310-200',
            'latitude' => -23.56140000,
            'longitude' => -46.65590000,
        ];

        // Demo Tutor
        $tutor = User::updateOrCreate(
            ['email' => 'tutor@2pets.com.br'],
            [
                'name' => 'Carlos Oliveira',
                'password' => Hash::make('password'),
                'role' => 'tutor',
                'cpf' => '12345678900',
                'phone' => '(11) 99999-1234',
                'email_verified_at' => now(),
                'registration_status' => 'completed',
                // `registration_status` e `profile_completed` sao INDEPENDENTES: o primeiro
                // e o estagio do cadastro, o segundo e a flag que o app le para decidir se
                // manda o usuario para `/complete-profile/*` (ver utils/postLoginRoute.js).
                // Sem esta linha o usuario demo loga e cai direto na tela de completar
                // cadastro -- exatamente o que ele deveria dispensar.
                'profile_completed' => true,
                'birth_date' => '1985-04-12',
                'gender' => 'male',
                'occupation' => 'Designer',
                ...$demoAddress,
            ]
        );
        $tutor->assignRole('tutor');

        // Demo Professional
        $profUser = User::updateOrCreate(
            ['email' => 'vet@2pets.com.br'],
            [
                'name' => 'Dra. Carolina Mendes',
                'password' => Hash::make('password'),
                'role' => 'professional',
                'user_type' => 'vet',
                'cpf' => '98765432100',
                'phone' => '(11) 98765-4321',
                'email_verified_at' => now(),
                'registration_status' => 'completed',
                'profile_completed' => true,
                'birth_date' => '1988-09-30',
                ...$demoAddress,
            ]
        );
        $profUser->assignRole('vet_freelancer');

        $professional = Professional::updateOrCreate(
            ['user_id' => $profUser->id],
            [
                'professional_type' => 'vet',
                'crmv' => '12345',
                'crmv_state' => 'SP',
                'description' => 'Veterinaria com 10 anos de experiencia em cardiologia e clinica geral de pequenos animais.',
                'university' => 'USP - Universidade de Sao Paulo',
                'graduation_year' => 2015,
                'experience_years' => 10,
                'service_radius_km' => 25,
                'average_rating' => 4.8,
                'total_reviews' => 0,
            ]
        );

        // Sem isto a conta de demonstração do profissional existe mas NÃO aparece na busca
        // pública — ver o docblock de `SearchableDemoProfessional`.
        (new SearchableDemoProfessional)->provision($profUser, $professional);

        // Demo Admin
        $admin = User::updateOrCreate(
            ['email' => 'admin@2pets.com.br'],
            [
                'name' => 'Admin 2pets',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'email_verified_at' => now(),
                'registration_status' => 'completed',
                'profile_completed' => true,
            ]
        );
        $admin->assignRole('admin');

        // Demo Pets for tutor
        $pets = [
            [
                'name' => 'Bella',
                'species' => 'dog',
                'breed' => 'Golden Retriever',
                'gender' => 'female',
                'birth_date' => now()->subYears(2)->subMonths(3),
                'weight' => 28.5,
                'neutered' => true,
                'color' => 'Dourado',
            ],
            [
                'name' => 'Max',
                'species' => 'cat',
                'breed' => 'Siames',
                'gender' => 'male',
                'birth_date' => now()->subYear()->subMonths(6),
                'weight' => 4.2,
                'neutered' => true,
                'color' => 'Creme e marrom',
            ],
            [
                'name' => 'Luna',
                'species' => 'dog',
                'breed' => 'Poodle',
                'gender' => 'female',
                'birth_date' => now()->subMonths(8),
                'weight' => 3.8,
                'neutered' => false,
                'color' => 'Branco',
            ],
        ];

        foreach ($pets as $petData) {
            $pet = Pet::updateOrCreate(
                ['user_id' => $tutor->id, 'name' => $petData['name']],
                $petData
            );

            // Vaccinations
            $vaccines = [
                ['vaccine_name' => 'V10', 'application_date' => now()->subMonths(6), 'next_dose_date' => now()->addDays(5), 'dose_number' => 1],
                ['vaccine_name' => 'Antirrabica', 'application_date' => now()->subYear(), 'next_dose_date' => now()->addMonths(1), 'dose_number' => 1],
                ['vaccine_name' => 'Gripe Canina', 'application_date' => now()->subMonths(4), 'next_dose_date' => now()->addMonths(2), 'dose_number' => 1],
            ];

            // `vaccinations.professional_id` — e `appointments.professional_id` mais
            // abaixo — sao FK para `users.id`, NAO para `professionals.id`. Passar
            // `$professional->id` so nao estourava quando os dois ids coincidiam por
            // acaso; num banco recem-criado da violacao de chave estrangeira.
            foreach ($vaccines as $vac) {
                Vaccination::updateOrCreate(
                    ['pet_id' => $pet->id, 'vaccine_name' => $vac['vaccine_name']],
                    array_merge($vac, ['professional_id' => $profUser->id])
                );
            }

            // Deworming
            PetDeworming::updateOrCreate(
                ['pet_id' => $pet->id, 'product_name' => 'Drontal Plus'],
                [
                    'product_name' => 'Drontal Plus',
                    'applied_date' => now()->subMonths(3),
                    'next_date' => now()->addDays(10),
                    'notes' => '1 comprimido',
                ]
            );
        }

        // Demo Appointments
        $petIds = Pet::where('user_id', $tutor->id)->pluck('id');
        $appointmentData = [
            [
                'appointment_date' => now()->addDays(2)->format('Y-m-d'),
                'appointment_time' => '10:00',
                'type' => 'consultation',
                'reason' => 'Consulta de Rotina',
                'status' => 'confirmed',
                'duration' => 30,
                'price' => 250.00,
            ],
            [
                'appointment_date' => now()->addDays(7)->format('Y-m-d'),
                'appointment_time' => '14:30',
                'type' => 'vaccination',
                'reason' => 'Vacinacao',
                'status' => 'scheduled',
                'duration' => 15,
                'price' => 120.00,
            ],
            [
                'appointment_date' => now()->subDays(5)->format('Y-m-d'),
                'appointment_time' => '09:00',
                'type' => 'consultation',
                'reason' => 'Check-up Cardiologico',
                'status' => 'completed',
                'duration' => 60,
                'price' => 450.00,
            ],
        ];

        foreach ($appointmentData as $i => $appt) {
            Appointment::updateOrCreate(
                [
                    'client_id' => $tutor->id,
                    'professional_id' => $profUser->id,
                    'appointment_date' => $appt['appointment_date'],
                    'appointment_time' => $appt['appointment_time'],
                ],
                array_merge($appt, [
                    'client_id' => $tutor->id,
                    'professional_id' => $profUser->id,
                    'pet_id' => $petIds[$i % count($petIds)] ?? $petIds[0],
                ])
            );
        }

        $this->command->info('Demo data seeded: tutor@2pets.com.br / vet@2pets.com.br / admin@2pets.com.br (password: password)');
    }
}
