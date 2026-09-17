<?php

namespace Tests\Feature;

use App\Enums\PetVetAccessOrigin;
use App\Mail\PetRegisteredMail;
use App\Models\ConsentLog;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\RegistrationContinuationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `POST professional/appointments/new-patient` — contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md`.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`. A
 * cobertura foi validada manualmente via `curl` contra a API em execução (ver relatório da
 * tarefa) — inclusive o formato exato do 409, fixado em conjunto com o frontend.
 */
class NewPatientAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->professional = User::factory()->professional()->create();
        Sanctum::actingAs($this->professional);
    }

    public function test_creates_tutor_pet_and_appointment_without_email(): void
    {
        $response = $this->postJson('/api/professional/appointments/new-patient', $this->basePayload());

        $response->assertCreated()
            ->assertJsonPath('data.claim_link_sent', false)
            ->assertJsonPath('data.tutor.is_unclaimed', true);

        $tutor = User::where('cpf', '39053344705')->firstOrFail();
        $this->assertNull($tutor->password);
        $this->assertSame('pending', $tutor->registration_status);
        $this->assertNull($tutor->email);

        $pet = Pet::where('user_id', $tutor->id)->firstOrFail();
        $this->assertSame('Toddy', $pet->name);
        $this->assertSame('dog', $pet->species);

        $this->assertDatabaseHas('appointments', [
            'professional_id' => $this->professional->id,
            'client_id' => $tutor->id,
            'pet_id' => $pet->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_grants_write_access_and_links_professional_client(): void
    {
        $this->postJson('/api/professional/appointments/new-patient', $this->basePayload())
            ->assertCreated();

        $tutor = User::where('cpf', '39053344705')->firstOrFail();
        $pet = Pet::where('user_id', $tutor->id)->firstOrFail();

        $access = PetVetAccess::where('pet_id', $pet->id)->firstOrFail();
        $this->assertSame($this->professional->id, $access->veterinarian_id);
        $this->assertSame('write', $access->access_level->value);
        $this->assertSame('accepted', $access->status);
        $this->assertTrue($access->is_active);
        $this->assertSame(PetVetAccessOrigin::NEW_PATIENT_SELF_GRANT, $access->origin);

        $this->assertDatabaseHas('professional_clients', [
            'professional_id' => $this->professional->id,
            'client_id' => $tutor->id,
        ]);
    }

    public function test_reuses_existing_tutor_by_cpf_instead_of_duplicating(): void
    {
        $existingTutor = User::factory()->tutor()->create(['cpf' => '39053344705']);

        $this->postJson('/api/professional/appointments/new-patient', $this->basePayload())
            ->assertCreated()
            ->assertJsonPath('data.tutor.id', $existingTutor->id);

        $this->assertSame(1, User::where('cpf', '39053344705')->count());
    }

    public function test_email_belonging_to_another_cpf_is_ignored(): void
    {
        User::factory()->tutor()->create(['email' => 'terceiro@exemplo.com']);

        $payload = $this->basePayload();
        $payload['tutor_email'] = 'terceiro@exemplo.com';

        $this->postJson('/api/professional/appointments/new-patient', $payload)->assertCreated();

        $newTutor = User::where('cpf', '39053344705')->firstOrFail();
        $this->assertNull($newTutor->email);

        Mail::assertNothingSent();
    }

    public function test_marketing_opt_in_writes_consent_log_and_sends_email_with_continuation_link(): void
    {
        $payload = $this->basePayload();
        $payload['tutor_email'] = 'carlos@exemplo.com';
        $payload['marketing_opt_in'] = true;

        $response = $this->postJson('/api/professional/appointments/new-patient', $payload);

        $response->assertCreated()->assertJsonPath('data.claim_link_sent', true);

        $tutor = User::where('cpf', '39053344705')->firstOrFail();

        $this->assertDatabaseHas('consent_logs', [
            'user_id' => $tutor->id,
            'consent_key' => 'marketing_email',
            'granted' => true,
            'source' => 'professional_new_patient',
        ]);
        $this->assertTrue((bool) $tutor->marketing_consent);

        $this->assertSame(1, RegistrationContinuationToken::where('user_id', $tutor->id)->count());
        Mail::assertSent(PetRegisteredMail::class);
    }

    public function test_no_email_means_nothing_is_sent_and_appointment_still_succeeds(): void
    {
        $this->postJson('/api/professional/appointments/new-patient', $this->basePayload())
            ->assertCreated();

        Mail::assertNothingSent();
        $this->assertSame(0, ConsentLog::count());
    }

    public function test_invalid_cpf_is_rejected_with_422(): void
    {
        $payload = $this->basePayload();
        $payload['tutor_cpf'] = '111.111.111-11';

        $this->postJson('/api/professional/appointments/new-patient', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.tutor_cpf.0', 'CPF inválido');
    }

    public function test_missing_pet_species_is_rejected_with_422(): void
    {
        $payload = $this->basePayload();
        unset($payload['pet_species']);

        $this->postJson('/api/professional/appointments/new-patient', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('pet_species');
    }

    public function test_duplicate_pet_name_returns_409_with_candidates_at_the_root(): void
    {
        $tutor = User::factory()->tutor()->create(['cpf' => '39053344705']);
        $existingPet = Pet::factory()->create(['user_id' => $tutor->id, 'name' => 'Toddy', 'species' => 'dog']);

        $response = $this->postJson('/api/professional/appointments/new-patient', $this->basePayload());

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Este tutor já tem um pet com este nome.')
            ->assertJsonPath('candidates.0.id', $existingPet->id)
            ->assertJsonPath('candidates.0.name', 'Toddy')
            ->assertJsonPath('candidates.0.species', 'dog')
            ->assertJsonStructure(['message', 'candidates' => [['id', 'name', 'species', 'age', 'last_appointment_at']]]);

        $this->assertDatabaseMissing('appointments', ['client_id' => $tutor->id]);
    }

    public function test_resending_with_existing_pet_id_reuses_the_pet(): void
    {
        $tutor = User::factory()->tutor()->create(['cpf' => '39053344705']);
        $existingPet = Pet::factory()->create(['user_id' => $tutor->id, 'name' => 'Toddy', 'species' => 'dog']);

        $payload = $this->basePayload();
        $payload['existing_pet_id'] = $existingPet->id;

        $this->postJson('/api/professional/appointments/new-patient', $payload)
            ->assertCreated()
            ->assertJsonPath('data.pet.id', $existingPet->id);

        $this->assertSame(1, Pet::where('user_id', $tutor->id)->count());
    }

    public function test_resending_with_create_new_pet_creates_a_homonym(): void
    {
        $tutor = User::factory()->tutor()->create(['cpf' => '39053344705']);
        Pet::factory()->create(['user_id' => $tutor->id, 'name' => 'Toddy', 'species' => 'cat']);

        $payload = $this->basePayload();
        $payload['create_new_pet'] = true;

        $this->postJson('/api/professional/appointments/new-patient', $payload)->assertCreated();

        $this->assertSame(2, Pet::where('user_id', $tutor->id)->where('name', 'Toddy')->count());
    }

    /** @return array<string, mixed> */
    private function basePayload(): array
    {
        return [
            'tutor_cpf' => '390.533.447-05',
            'tutor_name' => 'Carlos Oliveira',
            'pet_name' => 'Toddy',
            'pet_species' => 'dog',
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '14:00',
            'type' => 'consultation',
        ];
    }
}
