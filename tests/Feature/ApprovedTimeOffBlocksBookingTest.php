<?php

namespace Tests\Feature;

use App\Models\Availability;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Professional;
use App\Models\StaffTimeOff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Item 21 do backlog gap-simplesvet — `OrganizationMember::isAvailableOn()` já existia, mas
 * nada no fluxo de agendamento público chamava. Uma folga aprovada precisa impedir
 * agendamento no dia coberto, exatamente como já impedia "em teoria".
 */
class ApprovedTimeOffBlocksBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_time_off_blocks_booking_for_the_covered_date(): void
    {
        $tutor = User::factory()->tutor()->create();
        $professional = User::factory()->professional()->create();
        Professional::factory()->create(['user_id' => $professional->id]);
        $organization = Organization::factory()->create();

        $member = OrganizationMember::factory()
            ->for($organization)
            ->for($professional, 'user')
            ->create();

        $nextMonday = now()->next(Carbon::MONDAY);

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

        StaffTimeOff::create([
            'staff_id' => $member->id,
            'type' => 'vacation',
            'start_date' => $nextMonday->toDateString(),
            'end_date' => $nextMonday->toDateString(),
            'status' => 'approved',
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

    public function test_pending_time_off_does_not_block_booking(): void
    {
        $tutor = User::factory()->tutor()->create();
        $professional = User::factory()->professional()->create();
        Professional::factory()->create(['user_id' => $professional->id]);
        $organization = Organization::factory()->create();

        $member = OrganizationMember::factory()
            ->for($organization)
            ->for($professional, 'user')
            ->create();

        $nextMonday = now()->next(Carbon::MONDAY);

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

        StaffTimeOff::create([
            'staff_id' => $member->id,
            'type' => 'vacation',
            'start_date' => $nextMonday->toDateString(),
            'end_date' => $nextMonday->toDateString(),
            'status' => 'pending',
        ]);

        Sanctum::actingAs($tutor);

        $response = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'professional_id' => $professional->id,
            'organization_id' => $organization->id,
            'date' => $nextMonday->toDateString(),
        ]));

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }
}
