<?php

namespace Tests\Feature;

use App\Models\BlockedTime;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 1 do fluxo de agendamento: CRUD de `blocked_times` (férias, almoço, compromisso).
 * `AvailabilityService` já lia esta tabela para excluir horários do slot público; até esta
 * fase não havia nenhum endpoint que a escrevesse.
 */
class BlockedTimeManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
    }

    public function test_professional_can_list_own_blocked_times(): void
    {
        BlockedTime::create([
            'professional_id' => $this->professional->id,
            'start_datetime' => now()->addDay()->setTime(12, 0),
            'end_datetime' => now()->addDay()->setTime(13, 0),
            'reason' => 'Almoço',
        ]);

        Sanctum::actingAs($this->professional);

        $response = $this->getJson('/api/professional/blocked-times');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Almoço');
    }

    public function test_professional_can_create_a_blocked_time(): void
    {
        Sanctum::actingAs($this->professional);

        $start = now()->addWeek()->setTime(0, 0);
        $end = now()->addWeek()->addDays(6)->setTime(23, 59);

        $response = $this->postJson('/api/professional/blocked-times', [
            'start_datetime' => $start->toDateTimeString(),
            'end_datetime' => $end->toDateTimeString(),
            'reason' => 'Férias',
        ]);

        $response->assertCreated()->assertJsonPath('data.reason', 'Férias');
        $this->assertDatabaseHas('blocked_times', [
            'professional_id' => $this->professional->id,
            'reason' => 'Férias',
        ]);
    }

    public function test_end_datetime_must_be_after_start_datetime(): void
    {
        Sanctum::actingAs($this->professional);

        $response = $this->postJson('/api/professional/blocked-times', [
            'start_datetime' => now()->addDay()->setTime(13, 0)->toDateTimeString(),
            'end_datetime' => now()->addDay()->setTime(12, 0)->toDateTimeString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('end_datetime');
    }

    public function test_professional_cannot_create_blocked_time_for_another_professional(): void
    {
        $otherProfessional = User::factory()->professional()->create();

        Sanctum::actingAs($this->professional);

        $response = $this->postJson('/api/professional/blocked-times', [
            'professional_id' => $otherProfessional->id,
            'start_datetime' => now()->addDay()->setTime(12, 0)->toDateTimeString(),
            'end_datetime' => now()->addDay()->setTime(13, 0)->toDateTimeString(),
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('blocked_times', ['professional_id' => $otherProfessional->id]);
    }

    public function test_organization_owner_can_block_a_team_members_agenda(): void
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

        $response = $this->postJson('/api/professional/blocked-times', [
            'professional_id' => $veterinarian->id,
            'start_datetime' => now()->addWeek()->toDateTimeString(),
            'end_datetime' => now()->addWeek()->addDays(6)->toDateTimeString(),
            'reason' => 'Férias da equipe',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('blocked_times', [
            'professional_id' => $veterinarian->id,
            'organization_id' => $organization->id,
        ]);
    }

    public function test_professional_cannot_update_another_professionals_blocked_time(): void
    {
        $otherProfessional = User::factory()->professional()->create();

        $blockedTime = BlockedTime::create([
            'professional_id' => $otherProfessional->id,
            'start_datetime' => now()->addDay()->setTime(12, 0),
            'end_datetime' => now()->addDay()->setTime(13, 0),
        ]);

        Sanctum::actingAs($this->professional);

        $response = $this->putJson("/api/professional/blocked-times/{$blockedTime->id}", [
            'start_datetime' => now()->addDay()->setTime(14, 0)->toDateTimeString(),
            'end_datetime' => now()->addDay()->setTime(15, 0)->toDateTimeString(),
        ]);

        $response->assertStatus(403);
    }

    public function test_professional_can_delete_own_blocked_time_and_it_is_soft_deleted(): void
    {
        $blockedTime = BlockedTime::create([
            'professional_id' => $this->professional->id,
            'start_datetime' => now()->addDay()->setTime(12, 0),
            'end_datetime' => now()->addDay()->setTime(13, 0),
        ]);

        Sanctum::actingAs($this->professional);

        $response = $this->deleteJson("/api/professional/blocked-times/{$blockedTime->id}");

        $response->assertOk();
        $this->assertSoftDeleted('blocked_times', ['id' => $blockedTime->id]);
    }
}
