<?php

namespace Tests\Feature;

use App\Models\Availability;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 1 do fluxo de agendamento: CRUD de `availabilities` pelo próprio profissional, com o
 * dono de organização podendo escrever a agenda de um colega da MESMA organização
 * (`AvailabilityPolicy`). Antes desta fase não havia nenhum endpoint de escrita.
 */
class AvailabilityManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
    }

    public function test_professional_can_list_own_weekly_availability(): void
    {
        Availability::create([
            'professional_id' => $this->professional->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '18:00',
            'slot_duration' => 30,
            'buffer_time' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->professional);

        $response = $this->getJson('/api/professional/availability');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.day_of_week', 1);
    }

    public function test_professional_can_create_an_availability_window(): void
    {
        Sanctum::actingAs($this->professional);

        $response = $this->postJson('/api/professional/availability', [
            'day_of_week' => 2,
            'start_time' => '08:00',
            'end_time' => '18:00',
            'slot_duration' => 30,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.professional_id', $this->professional->id)
            ->assertJsonPath('data.buffer_time', 0)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('availabilities', [
            'professional_id' => $this->professional->id,
            'day_of_week' => 2,
        ]);
    }

    public function test_creating_an_overlapping_window_is_rejected(): void
    {
        Availability::create([
            'professional_id' => $this->professional->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'slot_duration' => 30,
            'buffer_time' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->professional);

        $response = $this->postJson('/api/professional/availability', [
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration' => 30,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('start_time');
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        Sanctum::actingAs($this->professional);

        $response = $this->postJson('/api/professional/availability', [
            'day_of_week' => 1,
            'start_time' => '18:00',
            'end_time' => '08:00',
            'slot_duration' => 30,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('end_time');
    }

    public function test_professional_cannot_create_availability_for_another_professional(): void
    {
        $otherProfessional = User::factory()->professional()->create();

        Sanctum::actingAs($this->professional);

        $response = $this->postJson('/api/professional/availability', [
            'professional_id' => $otherProfessional->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '18:00',
            'slot_duration' => 30,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('availabilities', ['professional_id' => $otherProfessional->id]);
    }

    public function test_organization_owner_can_create_availability_for_a_team_member(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->professional()->create();
        $veterinarian = User::factory()->professional()->create();

        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $veterinarian->id,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/professional/availability', [
            'professional_id' => $veterinarian->id,
            'day_of_week' => 3,
            'start_time' => '08:00',
            'end_time' => '18:00',
            'slot_duration' => 30,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('availabilities', [
            'professional_id' => $veterinarian->id,
            'organization_id' => $organization->id,
        ]);
    }

    public function test_professional_cannot_update_another_professionals_window(): void
    {
        $otherProfessional = User::factory()->professional()->create();

        $availability = Availability::create([
            'professional_id' => $otherProfessional->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '18:00',
            'slot_duration' => 30,
            'buffer_time' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->professional);

        $response = $this->putJson("/api/professional/availability/{$availability->id}", [
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration' => 30,
        ]);

        $response->assertStatus(403);
    }

    public function test_professional_can_update_own_window(): void
    {
        $availability = Availability::create([
            'professional_id' => $this->professional->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '18:00',
            'slot_duration' => 30,
            'buffer_time' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->professional);

        $response = $this->putJson("/api/professional/availability/{$availability->id}", [
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'slot_duration' => 45,
        ]);

        $response->assertOk()->assertJsonPath('data.slot_duration', 45);
        $this->assertDatabaseHas('availabilities', [
            'id' => $availability->id,
            'start_time' => '09:00:00',
            'slot_duration' => 45,
        ]);
    }

    public function test_professional_can_delete_own_window_and_it_is_soft_deleted(): void
    {
        $availability = Availability::create([
            'professional_id' => $this->professional->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '18:00',
            'slot_duration' => 30,
            'buffer_time' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->professional);

        $response = $this->deleteJson("/api/professional/availability/{$availability->id}");

        $response->assertOk();
        $this->assertSoftDeleted('availabilities', ['id' => $availability->id]);
    }

    public function test_professional_can_replace_the_whole_week_at_once(): void
    {
        Availability::create([
            'professional_id' => $this->professional->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'slot_duration' => 30,
            'buffer_time' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->professional);

        $response = $this->putJson('/api/professional/availability/week', [
            'windows' => [
                ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '18:00', 'slot_duration' => 30],
                ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '18:00', 'slot_duration' => 30],
            ],
        ]);

        $response->assertOk()->assertJsonCount(2, 'data');

        // `replaceWeek()` soft-deleta a janela antiga (ver `AvailabilityManagementService`) —
        // por isso a contagem correta é sobre o escopo padrão do Eloquent (exclui
        // soft-deleted), não `assertDatabaseCount()`, que enxerga a linha física ainda.
        $this->assertSame(2, Availability::count());
        $this->assertDatabaseHas('availabilities', [
            'professional_id' => $this->professional->id,
            'day_of_week' => 1,
            'end_time' => '18:00:00',
        ]);
    }

    public function test_replace_week_rejects_internally_overlapping_windows(): void
    {
        Sanctum::actingAs($this->professional);

        $response = $this->putJson('/api/professional/availability/week', [
            'windows' => [
                ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00', 'slot_duration' => 30],
                ['day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '14:00', 'slot_duration' => 30],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('availabilities', 0);
    }
}
