<?php

namespace Tests\Feature;

use App\Models\Availability;
use App\Models\BlockedTime;
use App\Models\Organization;
use App\Models\Professional;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Item 21 do backlog gap-simplesvet — regressão do bug descrito em
 * `AvailableDaysCalculator::getBlockedTimesForProfessionals`/`AvailabilityService::getBlockedTimes`:
 * um `BlockedTime` amplo (`professional_id = null`, `organization_id` preenchido) precisa
 * esconder o slot de QUALQUER profissional daquele escopo, não só de quem bate
 * `professional_id` exatamente.
 */
class OrganizationWideBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_wide_block_hides_slots_for_every_professional_in_scope(): void
    {
        $tutor = User::factory()->tutor()->create();
        $professional = User::factory()->professional()->create();
        Professional::factory()->create(['user_id' => $professional->id]);
        $organization = Organization::factory()->create();

        $nextMonday = now()->next(Carbon::MONDAY);

        Availability::create([
            'professional_id' => $professional->id,
            'organization_id' => $organization->id,
            'day_of_week' => $nextMonday->dayOfWeek,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'slot_duration' => 30,
            'buffer_time' => 0,
            'is_active' => true,
        ]);

        // Bloqueio da EMPRESA inteira (feriado, fechamento) — sem professional_id.
        BlockedTime::create([
            'professional_id' => null,
            'organization_id' => $organization->id,
            'start_datetime' => $nextMonday->copy()->setTime(0, 0),
            'end_datetime' => $nextMonday->copy()->setTime(23, 59),
            'reason' => 'Feriado municipal',
        ]);

        Sanctum::actingAs($tutor);

        $response = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'professional_id' => $professional->id,
            'organization_id' => $organization->id,
            'date' => $nextMonday->toDateString(),
        ]));

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_professional_specific_block_does_not_leak_to_other_professionals(): void
    {
        $tutor = User::factory()->tutor()->create();
        $blockedProfessional = User::factory()->professional()->create();
        $freeProfessional = User::factory()->professional()->create();
        Professional::factory()->create(['user_id' => $blockedProfessional->id]);
        Professional::factory()->create(['user_id' => $freeProfessional->id]);
        $organization = Organization::factory()->create();

        $nextMonday = now()->next(Carbon::MONDAY);

        foreach ([$blockedProfessional, $freeProfessional] as $professional) {
            Availability::create([
                'professional_id' => $professional->id,
                'organization_id' => $organization->id,
                'day_of_week' => $nextMonday->dayOfWeek,
                'start_time' => '08:00',
                'end_time' => '10:00',
                'slot_duration' => 30,
                'buffer_time' => 0,
                'is_active' => true,
            ]);
        }

        BlockedTime::create([
            'professional_id' => $blockedProfessional->id,
            'organization_id' => $organization->id,
            'start_datetime' => $nextMonday->copy()->setTime(0, 0),
            'end_datetime' => $nextMonday->copy()->setTime(23, 59),
            'reason' => 'Compromisso pessoal',
        ]);

        Sanctum::actingAs($tutor);

        $response = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'professional_id' => $freeProfessional->id,
            'organization_id' => $organization->id,
            'date' => $nextMonday->toDateString(),
        ]));

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }
}
