<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Appointment;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contrato docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md §3:
 * `appointments.type` passa a ser o superconjunto de `ServiceCategory` — `checkup`/`exam`
 * (valores antigos, redundante/impreciso) não são mais aceitos em código novo, e as 7
 * categorias que antes não tinham correspondência (`laboratory`, `imaging`, `dental`,
 * `nutrition`, `behavioral`, `training`, `boarding`) passam a ser aceitas.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class AppointmentTypeTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private User $client;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
        $this->client = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->client->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->professional->id,
            'granted_by' => $this->client->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->professional);
    }

    public function test_store_rejects_the_legacy_checkup_type(): void
    {
        $response = $this->postJson('/api/professional/appointments', $this->payload('checkup'));

        $response->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_store_rejects_the_legacy_generic_exam_type(): void
    {
        $response = $this->postJson('/api/professional/appointments', $this->payload('exam'));

        $response->assertStatus(422)->assertJsonValidationErrors('type');
    }

    /**
     * @return array<string, string>
     */
    public static function newlySupportedCategoryProvider(): array
    {
        return [
            'laboratory' => ['laboratory'],
            'imaging' => ['imaging'],
            'dental' => ['dental'],
            'nutrition' => ['nutrition'],
            'behavioral' => ['behavioral'],
            'training' => ['training'],
            'boarding' => ['boarding'],
        ];
    }

    /**
     * @dataProvider newlySupportedCategoryProvider
     */
    public function test_store_accepts_every_category_the_fusion_unlocked(string $category): void
    {
        $response = $this->postJson('/api/professional/appointments', $this->payload($category));

        $response->assertStatus(201);
        $this->assertDatabaseHas('appointments', [
            'client_id' => $this->client->id,
            'pet_id' => $this->pet->id,
            'type' => $category,
        ]);
    }

    public function test_update_rejects_the_legacy_checkup_type(): void
    {
        $appointment = Appointment::create([
            'professional_id' => $this->professional->id,
            'client_id' => $this->client->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->addDay()->startOfDay(),
            'appointment_time' => '10:00:00',
            'duration' => 30,
            'type' => 'consultation',
            'status' => 'confirmed',
        ]);

        $response = $this->putJson("/api/professional/appointments/{$appointment->id}", ['type' => 'checkup']);

        $response->assertStatus(422)->assertJsonValidationErrors('type');
        $this->assertSame('consultation', $appointment->fresh()->type);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $type): array
    {
        return [
            'client_id' => $this->client->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '10:00',
            'type' => $type,
            'reason' => 'Checkup',
        ];
    }
}
