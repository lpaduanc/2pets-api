<?php

namespace Tests\Feature;

use App\Models\Availability;
use App\Models\BlockedTime;
use App\Models\Professional;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/public/booking/availability` (`AvailabilityService::getAvailableSlots()`)
 * SEMPRE devolvia `[]` em ambiente de desenvolvimento — não havia nenhum seeder nem endpoint
 * de escrita populando `availabilities`. Esta é a prova de que, com uma janela cadastrada
 * (agora possível via `Api/Professional/AvailabilityController`), o endpoint devolve slots
 * de verdade — e continua respeitando `blocked_times`.
 */
class AvailabilityServiceSlotsTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private User $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();
        $this->professional = User::factory()->professional()->create();
        Professional::factory()->create(['user_id' => $this->professional->id]);
    }

    public function test_public_availability_endpoint_returns_real_slots_once_the_professional_has_a_weekly_window(): void
    {
        $nextMonday = now()->next(Carbon::MONDAY);

        Availability::create([
            'professional_id' => $this->professional->id,
            'day_of_week' => $nextMonday->dayOfWeek,
            'start_time' => '08:00',
            'end_time' => '10:00',
            'slot_duration' => 30,
            'buffer_time' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->tutor);

        $response = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'professional_id' => $this->professional->id,
            'date' => $nextMonday->toDateString(),
        ]));

        $response->assertOk();
        $slots = $response->json('data');

        $this->assertCount(4, $slots);
        $this->assertSame('08:00', substr($slots[0]['start_time'], 11, 5));
    }

    public function test_public_availability_endpoint_excludes_slots_covered_by_a_blocked_time(): void
    {
        $nextMonday = now()->next(Carbon::MONDAY);

        Availability::create([
            'professional_id' => $this->professional->id,
            'day_of_week' => $nextMonday->dayOfWeek,
            'start_time' => '08:00',
            'end_time' => '10:00',
            'slot_duration' => 30,
            'buffer_time' => 0,
            'is_active' => true,
        ]);

        BlockedTime::create([
            'professional_id' => $this->professional->id,
            'start_datetime' => $nextMonday->copy()->setTime(8, 0),
            'end_datetime' => $nextMonday->copy()->setTime(9, 0),
            'reason' => 'Compromisso',
        ]);

        Sanctum::actingAs($this->tutor);

        $response = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'professional_id' => $this->professional->id,
            'date' => $nextMonday->toDateString(),
        ]));

        $response->assertOk();
        $slots = $response->json('data');

        $this->assertCount(2, $slots);
        $this->assertSame('09:00', substr($slots[0]['start_time'], 11, 5));
    }

    /**
     * Regressão (Fase 4, achada pelo frontend): `AvailabilityService::
     * getAvailabilityWindowsForDay()` usava `->first()`, então a segunda janela do mesmo
     * dia (aqui, a tarde) era ignorada em silêncio — o caso mais comum de clínica
     * ("manhã e tarde com intervalo de almoço") nunca aparecia na agenda pública, mesmo
     * a escrita (Fase 1) já aceitando e validando as duas janelas sem erro nenhum.
     */
    public function test_public_availability_endpoint_returns_slots_from_both_morning_and_afternoon_windows(): void
    {
        $nextMonday = now()->next(Carbon::MONDAY);

        Availability::create([
            'professional_id' => $this->professional->id,
            'day_of_week' => $nextMonday->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'slot_duration' => 30,
            'buffer_time' => 0,
            'is_active' => true,
        ]);

        Availability::create([
            'professional_id' => $this->professional->id,
            'day_of_week' => $nextMonday->dayOfWeek,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'slot_duration' => 30,
            'buffer_time' => 0,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->tutor);

        $response = $this->getJson('/api/public/booking/availability?'.http_build_query([
            'professional_id' => $this->professional->id,
            'date' => $nextMonday->toDateString(),
        ]));

        $response->assertOk();
        $slots = collect($response->json('data'))->map(fn (array $slot): string => substr($slot['start_time'], 11, 5));

        // 6 slots de manhã (09:00-12:00) + 4 de tarde (14:00-16:00) = 10.
        $this->assertCount(10, $slots);
        $this->assertTrue($slots->contains('09:00'), 'Slot da manhã ausente — a primeira janela do dia sumiu.');
        $this->assertTrue($slots->contains('14:00'), 'Slot da tarde ausente — a segunda janela do dia sumiu.');
        // O intervalo de almoço (12:00-14:00) nunca pode virar slot.
        $this->assertFalse($slots->contains('12:00'));
        $this->assertFalse($slots->contains('12:30'));
        $this->assertFalse($slots->contains('13:00'));
        $this->assertFalse($slots->contains('13:30'));
    }
}
