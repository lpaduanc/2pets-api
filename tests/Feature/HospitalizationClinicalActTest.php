<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Appointment;
use App\Models\Hospitalization;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ato clínico Grupo A (ex.: cirurgia) durante uma internação ativa — contrato
 * docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §2.2, pendência
 * marcada como "sinalizada, não decidida" na entrega anterior e resolvida nesta.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`
 * (verificado ao vivo via `curl` contra o ambiente de dev — ver relato da tarefa).
 */
class HospitalizationClinicalActTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    private User $tutor;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = User::factory()->professional()->create();
        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->vet);
    }

    public function test_opening_a_group_a_act_hangs_a_medical_record_on_the_stay_appointment_and_bills_the_same_invoice(): void
    {
        $hospitalization = $this->admit();

        $response = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/clinical-acts", [
            'category' => 'surgery',
            'reason' => 'Ovariohisterectomia',
            'unit_price' => 650,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.appointment_id', $hospitalization->appointment_id)
            ->assertJsonPath('data.act_category', 'surgery')
            ->assertJsonPath('data.status', 'draft');

        $this->assertSame(
            1,
            Invoice::where('appointment_id', $hospitalization->appointment_id)->count()
        );
        $this->assertDatabaseHas('appointment_charges', [
            'appointment_id' => $hospitalization->appointment_id,
            'description' => 'Ovariohisterectomia — durante internação',
        ]);
    }

    /**
     * Uma internação de vários dias pode ter mais de um ato Grupo A (ex.: duas cirurgias em
     * dias diferentes) — os dois `MedicalRecord` pendurados no MESMO `appointment_id`
     * precisam ser distinguíveis por `act_category`, não só por `id`/data.
     */
    public function test_a_second_act_during_the_same_stay_is_distinguishable_by_act_category(): void
    {
        $hospitalization = $this->admit();

        $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/clinical-acts", [
            'category' => 'surgery',
            'unit_price' => 650,
        ])->assertStatus(201);

        $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/clinical-acts", [
            'category' => 'emergency',
            'unit_price' => 200,
        ])->assertStatus(201);

        $records = MedicalRecord::where('appointment_id', $hospitalization->appointment_id)->get();

        $this->assertCount(2, $records);
        $categories = $records->map(fn (MedicalRecord $record): string => $record->act_category->value)->sort()->values()->all();
        $this->assertSame(['emergency', 'surgery'], $categories);
        $this->assertSame(
            1,
            Invoice::where('appointment_id', $hospitalization->appointment_id)->count()
        );
    }

    /**
     * Grupo A: peso + (diagnóstico OU plano) — a mesma regra de qualquer prontuário
     * clínico padrão, sem exceção por ter nascido durante uma internação.
     */
    public function test_finalizing_the_act_requires_weight_and_diagnosis_or_plan(): void
    {
        $record = $this->openSurgeryAct();

        $this->postJson("/api/professional/medical-records/{$record->id}/finalize")
            ->assertStatus(422);

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'weight' => 4.3,
            'diagnosis' => 'Cio persistente, indicação de OVH eletiva',
        ])->assertOk();

        $this->postJson("/api/professional/medical-records/{$record->id}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', 'finalized');
    }

    /**
     * `MedicalRecordFinalizationService::finalize()` nunca toca `Appointment.status` — a
     * internação continua `active`/cobrável depois que o ato é finalizado.
     */
    public function test_finalizing_the_act_does_not_close_the_hospitalization_or_its_appointment(): void
    {
        $hospitalization = $this->admit();
        $record = $this->openSurgeryAct($hospitalization);

        $this->putJson("/api/professional/medical-records/{$record->id}", [
            'weight' => 4.3,
            'treatment_plan' => 'OVH realizada sem intercorrências',
        ])->assertOk();
        $this->postJson("/api/professional/medical-records/{$record->id}/finalize")->assertOk();

        $this->assertSame('active', $hospitalization->fresh()->status);
        $this->assertSame('in_progress', Appointment::findOrFail($hospitalization->appointment_id)->status);

        // A internação continua cobrável: uma diária lançada depois de finalizar o ato
        // ainda entra normalmente na mesma comanda.
        $this->postJson("/api/professional/appointments/{$hospitalization->appointment_id}/charges", [
            'description' => 'Diária de internação',
            'unit_price' => 200,
        ])->assertStatus(201);
    }

    /**
     * @dataProvider nonGroupACategoryProvider
     */
    public function test_rejects_categories_outside_group_a(string $category): void
    {
        $hospitalization = $this->admit();

        $response = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/clinical-acts", [
            'category' => $category,
            'unit_price' => 100,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, MedicalRecord::count());
    }

    /** @return array<string, array{0: string}> */
    public static function nonGroupACategoryProvider(): array
    {
        return [
            'grooming (Grupo D, não clínico)' => ['grooming'],
            'laboratory (Grupo C, vira Exam, não MedicalRecord)' => ['laboratory'],
            'vaccination (Grupo B, regra própria de finalização)' => ['vaccination'],
        ];
    }

    public function test_requires_a_catalog_service_or_a_unit_price(): void
    {
        $hospitalization = $this->admit();

        $response = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/clinical-acts", [
            'category' => 'consultation',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('unit_price');
        $this->assertSame(0, MedicalRecord::count());
    }

    public function test_requires_the_hospitalization_to_be_active(): void
    {
        $hospitalization = $this->admit();
        $this->putJson("/api/professional/hospitalizations/{$hospitalization->id}", [
            'status' => 'discharged',
            'discharge_date' => now()->toDateString(),
            'discharge_summary' => 'Alta sem intercorrências.',
        ])->assertOk();

        $response = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/clinical-acts", [
            'category' => 'surgery',
            'unit_price' => 650,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, MedicalRecord::count());
    }

    /** Mesma autorização de quem edita a comanda (`AppointmentPolicy::manageCharges`), não PetVetAccess. */
    public function test_forbidden_for_a_professional_without_manage_charges_authorization(): void
    {
        $hospitalization = $this->admit();

        $stranger = User::factory()->professional()->create();
        Sanctum::actingAs($stranger);

        $response = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/clinical-acts", [
            'category' => 'surgery',
            'unit_price' => 650,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, MedicalRecord::count());
    }

    /**
     * Contrato docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §1:
     * nutrição/comportamento só são ato clínico exclusivo quando o executor é veterinário.
     */
    public function test_nutrition_as_a_group_a_act_requires_a_veterinarian(): void
    {
        $nonVetProfessional = User::factory()->professional()->create();
        $nonVetProfessional->syncRoles([Role::findOrCreate('petshop_owner', 'web')]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $nonVetProfessional->id,
            'granted_by' => $this->tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);
        Sanctum::actingAs($nonVetProfessional);

        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Observação nutricional',
        ]);
        $response->assertStatus(201);
        $hospitalizationId = $response->json('data.id');

        $act = $this->postJson("/api/professional/hospitalizations/{$hospitalizationId}/clinical-acts", [
            'category' => 'nutrition',
            'unit_price' => 120,
        ]);

        $act->assertStatus(422);
        $this->assertSame(0, MedicalRecord::count());
    }

    private function admit(): Hospitalization
    {
        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Internação de teste',
        ]);

        $response->assertStatus(201);

        return Hospitalization::findOrFail($response->json('data.id'));
    }

    private function openSurgeryAct(?Hospitalization $hospitalization = null): MedicalRecord
    {
        $hospitalization ??= $this->admit();

        $response = $this->postJson("/api/professional/hospitalizations/{$hospitalization->id}/clinical-acts", [
            'category' => 'surgery',
            'reason' => 'Ovariohisterectomia',
            'unit_price' => 650,
        ]);
        $response->assertStatus(201);

        return MedicalRecord::findOrFail($response->json('data.id'));
    }
}
