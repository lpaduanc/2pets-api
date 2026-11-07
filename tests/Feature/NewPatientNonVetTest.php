<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\ProfessionalClient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Paciente novo pelo agendamento para pet shop e banho e tosa (pedido do usuário, taxonomia de
 * `docs/taxonomia-professional-type.md`): tosador e pet shop criam tutor, pet e agendamento não
 * clínico; o pet aparece para eles sem nenhum acesso clínico (`PetVetAccess`); recepção de
 * clínica e tutor continuam com 403; `can_register_new_patient` no usuário.
 */
class NewPatientNonVetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_clinic_groomer_registers_a_grooming_patient_without_clinical_access_and_sees_the_pet(): void
    {
        $groomer = $this->member(Organization::factory()->create(), OrganizationRole::GROOMER);

        // Sem `type`: o padrão para quem não é veterinário é banho e tosa.
        $this->newPatient($groomer, ['type' => null])->assertCreated()->assertJsonPath('data.appointment.type', 'grooming');

        $tutor = User::where('cpf', '39053344705')->firstOrFail();
        $pet = Pet::where('user_id', $tutor->id)->sole();
        $this->assertSame(0, PetVetAccess::count());
        $this->assertTrue(ProfessionalClient::where('professional_id', $groomer->id)->where('client_id', $tutor->id)->exists());
        $this->assertSame('grooming', Appointment::sole()->type);
        $this->assertContains($pet->id, collect($this->actingAs($groomer, 'sanctum')->getJson("/api/professional/clients/{$tutor->id}/pets")->assertOk()->json())->pluck('id')->all());

        // Atendimento clínico continua só do veterinário.
        $this->newPatient($groomer, ['type' => 'consultation', 'create_new_pet' => true])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_petshop_professional_and_petshop_receptionist_can_register(): void
    {
        $petshopPro = User::factory()->create(['role' => 'professional', 'user_type' => 'petshop', 'registration_status' => 'approved']);
        $this->newPatient($petshopPro, ['type' => 'boarding'])->assertCreated();
        $this->actingAs($petshopPro, 'sanctum')->getJson('/api/user')->assertOk()->assertJsonPath('data.can_register_new_patient', true)->assertJsonPath('data.is_veterinarian', false);

        $desk = $this->member(Organization::factory()->petshop()->create(), OrganizationRole::RECEPTIONIST);
        $this->newPatient($desk, ['tutor_cpf' => '529.982.247-25', 'pet_name' => 'Mel'])->assertCreated();
        $this->assertSame(0, PetVetAccess::count());
    }

    public function test_clinic_receptionist_tutor_and_trainer_are_refused_and_vets_keep_working(): void
    {
        $clinic = Organization::factory()->create();
        $desk = $this->member($clinic, OrganizationRole::RECEPTIONIST);
        $tutor = User::factory()->tutor()->create();
        $trainer = User::factory()->create(['role' => 'professional', 'user_type' => 'training', 'registration_status' => 'approved']);

        foreach ([$desk, $tutor, $trainer] as $user) {
            $this->newPatient($user)->assertForbidden()->assertJsonPath('message', 'Seu perfil não pode cadastrar paciente novo pelo agendamento.');
            $this->actingAs($user, 'sanctum')->getJson('/api/user')->assertOk()->assertJsonPath('data.can_register_new_patient', false);
        }

        $vet = User::factory()->professional()->create();
        $this->newPatient($vet, ['type' => null])->assertCreated()->assertJsonPath('data.appointment.type', 'consultation');
        $this->assertSame(1, PetVetAccess::count());
        $this->actingAs($vet, 'sanctum')->getJson('/api/user')->assertOk()->assertJsonPath('data.can_register_new_patient', true);
    }

    private function member(Organization $organization, OrganizationRole $role): User
    {
        $user = User::factory()->create(['role' => 'professional', 'user_type' => null, 'registration_status' => 'approved']);
        OrganizationMember::factory()->for($organization, 'organization')->create(['user_id' => $user->id, 'role' => $role->value]);

        return $user->fresh();
    }

    /** @param  array<string, mixed>  $overrides */
    private function newPatient(User $user, array $overrides = []): TestResponse
    {
        $payload = array_filter($overrides + [
            'tutor_cpf' => '390.533.447-05', 'tutor_name' => 'Carlos Oliveira', 'pet_name' => 'Toddy', 'pet_species' => 'dog',
            'appointment_date' => now()->addDay()->toDateString(), 'appointment_time' => '14:00', 'type' => 'grooming',
        ], fn ($value): bool => $value !== null);

        return $this->actingAs($user, 'sanctum')->postJson('/api/professional/appointments/new-patient', $payload);
    }
}
