<?php

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Enums\ServiceCategory;
use App\Models\Availability;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Services\Organization\TeamSpecialtyAggregator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Cenário que a Fase 2 do fluxo de agendamento vai precisar e que não existia em nenhum
 * seeder: uma CLÍNICA (`Organization`) com equipe de 3 veterinários (`organization_members`),
 * um deles cardiologista — usado para testar a busca por especialidade da equipe.
 *
 * NÃO passa por `RegistrationCompletionService`/`BusinessOrganizationRegistrar`: aquele fluxo
 * depende de `VeterinarianCrmvGuard`/`CrmvValidationService` (validação externa de CRMV) e de
 * payload no formato exato de `CompleteGenericProfessionalRegistrationRequest` — frágil e
 * lento para dado sintético. Este seeder replica fielmente o RESULTADO do registrar (mesmos
 * campos gravados em `Organization`, mesma criação de `OrganizationMember` com `role=owner`,
 * mesmos papéis Spatie por tipo de cargo — `OrganizationRole::spatieRole()`), sem depender do
 * pipeline de cadastro. Idempotente via `updateOrCreate`/`firstOrCreate`, igual ao resto do
 * `DatabaseSeeder`.
 */
class ClinicOrganizationSeeder extends Seeder
{
    private const CNPJ = '12345678000199';

    private const ADDRESS = [
        'address' => 'Rua Harmonia',
        'number' => '400',
        'neighborhood' => 'Vila Madalena',
        'city' => 'São Paulo',
        'state' => 'SP',
        'zip_code' => '05435-000',
        'latitude' => -23.556700,
        'longitude' => -46.691900,
    ];

    /**
     * `category` usa o valor do serviço prestado (`ServiceCategory`) — não existe categoria
     * "cardiologia" no catálogo, que classifica por TIPO de ato (consulta, cirurgia...), não
     * por especialidade; a especialidade do profissional vive em `Professional::specialties`.
     *
     * @var list<array{email: string, name: string, crmv: string, specialties: list<string>, category: ServiceCategory, service: string}>
     */
    private const VETERINARIANS = [
        [
            'email' => 'dra.fernanda.cardio@2pets.com',
            'name' => 'Dra. Fernanda Ribeiro',
            'crmv' => '11111-SP',
            'specialties' => ['cardiologia'],
            'category' => ServiceCategory::CONSULTATION,
            'service' => 'Consulta Cardiológica',
        ],
        [
            'email' => 'dr.joao.geral@2pets.com',
            'name' => 'Dr. João Pereira',
            'crmv' => '22222-SP',
            'specialties' => ['clinica_geral'],
            'category' => ServiceCategory::CONSULTATION,
            'service' => 'Consulta Clínica Geral',
        ],
        [
            'email' => 'dra.patricia.cirurgia@2pets.com',
            'name' => 'Dra. Patrícia Souza',
            'crmv' => '33333-SP',
            'specialties' => ['cirurgia'],
            'category' => ServiceCategory::SURGERY,
            'service' => 'Cirurgia',
        ],
    ];

    /**
     * @var list<array{day_of_week: int, start_time: string, end_time: string}>
     */
    private const TEAM_WEEKLY_SCHEDULE = [
        ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '18:00'],
        ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '18:00'],
        ['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '18:00'],
        ['day_of_week' => 4, 'start_time' => '08:00', 'end_time' => '18:00'],
        ['day_of_week' => 5, 'start_time' => '08:00', 'end_time' => '18:00'],
    ];

    public function run(): void
    {
        $owner = $this->seedOwner();
        $organization = $this->seedOrganization();

        $this->ensureMembership($organization, $owner, OrganizationMember::ROLE_OWNER);
        $owner->assignRole('clinic_owner');

        foreach (self::VETERINARIANS as $veterinarianData) {
            $this->seedVeterinarian($organization, $veterinarianData);
        }

        // Fase 7: reconcilia `professionals.team_specialties` do dono explicitamente — os
        // membros acima já podem existir de uma rodada anterior deste seeder (idempotente,
        // `firstOrCreate`), e nesse caso o observer de `created` não dispara de novo.
        app(TeamSpecialtyAggregator::class)->syncForOrganization($organization);
    }

    private function seedOwner(): User
    {
        $owner = User::updateOrCreate(
            ['email' => 'dr.eduardo.dono@2pets.com'],
            [
                'name' => 'Dr. Eduardo Martins',
                'password' => Hash::make('password'),
                'role' => 'professional',
                'user_type' => 'clinic',
                'phone' => '(11) 95555-4444',
                'profile_completed' => true,
                'registration_status' => 'approved',
                'email_verified_at' => now(),
                'is_suspended' => false,
                ...self::ADDRESS,
            ]
        );

        Professional::updateOrCreate(
            ['user_id' => $owner->id],
            [
                'professional_type' => 'clinic',
                'business_name' => 'Clínica Vida Animal',
                'crmv' => '99999-SP',
                'crmv_state' => 'SP',
                'description' => 'Clínica veterinária com equipe multidisciplinar.',
                'experience_years' => 20,
                'average_rating' => 4.9,
                'total_reviews' => 300,
                'is_featured' => true,
            ]
        );

        return $owner;
    }

    private function seedOrganization(): Organization
    {
        return Organization::updateOrCreate(
            ['cnpj' => self::CNPJ],
            [
                'organization_type' => OrganizationType::CLINIC,
                'business_name' => 'Clínica Vida Animal',
                'description' => 'Clínica veterinária com equipe multidisciplinar: clínica geral, cardiologia e cirurgia.',
                'opening_hours' => '08:00',
                'closing_hours' => '18:00',
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                'service_radius_km' => 0,
                'services_offered' => ['consulta', 'cirurgia', 'cardiologia'],
                ...self::ADDRESS,
            ]
        );
    }

    /**
     * @param  array{email: string, name: string, crmv: string, specialties: list<string>, category: ServiceCategory, service: string}  $data
     */
    private function seedVeterinarian(Organization $organization, array $data): void
    {
        $veterinarian = User::updateOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['name'],
                'password' => Hash::make('password'),
                'role' => 'professional',
                'user_type' => 'vet',
                'phone' => '(11) 94444-3333',
                'profile_completed' => true,
                'registration_status' => 'approved',
                'email_verified_at' => now(),
                'is_suspended' => false,
                ...self::ADDRESS,
            ]
        );

        Professional::updateOrCreate(
            ['user_id' => $veterinarian->id],
            [
                'professional_type' => 'vet',
                'crmv' => $data['crmv'],
                'crmv_state' => 'SP',
                'specialties' => $data['specialties'],
                'description' => "Veterinário(a) da equipe da Clínica Vida Animal — {$data['service']}.",
                'experience_years' => 8,
                'average_rating' => 4.7,
                'total_reviews' => 40,
            ]
        );

        $this->ensureMembership($organization, $veterinarian, OrganizationMember::ROLE_VETERINARIAN);
        $veterinarian->assignRole('clinic_vet');

        Service::updateOrCreate(
            ['professional_id' => $veterinarian->id, 'name' => $data['service']],
            [
                'organization_id' => $organization->id,
                'category' => $data['category']->value,
                'duration' => 40,
                'price' => 220.00,
                'active' => true,
            ]
        );

        $this->seedWeeklySchedule($veterinarian, $organization);
    }

    private function seedWeeklySchedule(User $veterinarian, Organization $organization): void
    {
        Availability::where('professional_id', $veterinarian->id)->whereNull('location_id')->delete();

        foreach (self::TEAM_WEEKLY_SCHEDULE as $window) {
            Availability::create([
                ...$window,
                'professional_id' => $veterinarian->id,
                'organization_id' => $organization->id,
                'location_id' => null,
                'slot_duration' => 40,
                'buffer_time' => 10,
                'is_active' => true,
            ]);
        }
    }

    private function ensureMembership(Organization $organization, User $user, string $role): void
    {
        OrganizationMember::firstOrCreate(
            [
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => $role,
            ],
            [
                'hire_date' => now()->toDateString(),
                'is_active' => true,
            ]
        );
    }
}
