<?php

namespace Tests\Feature\Appointment;

use App\Models\Appointment;
use App\Models\AppointmentSeries;
use App\Models\Availability;
use App\Models\Holiday;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\ServicePackage;
use App\Models\SoldPackage;
use App\Models\SoldPackageSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Recorrência de agendamento — docs/gap-petflow/PLANO.md, 3.1: criação com e sem conflito
 * (pular/mover), edição e cancelamento "só este" / "este e os seguintes", extensão da janela pelo
 * job sem duplicar, pacote consumido na ocorrência realizada, outra organização e filtro.
 */
class AppointmentSeriesTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Segunda, 02/11/2026, meio-dia em São Paulo.
        Carbon::setTestNow('2026-11-02 15:00:00');
        $this->buildClinic();
        Holiday::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_creates_a_weekly_series_materializing_90_days_ahead(): void
    {
        $response = $this->createSeries(['count' => 20])->assertCreated()
            ->assertJsonPath('data.occurrence_index', 1)
            ->assertJsonPath('data.is_exception', false)
            ->assertJsonPath('data.series.freq', 'weekly')
            ->assertJsonPath('data.series.weekdays', [1])
            ->assertJsonPath('data.series.count', 20);

        // Segundas de 09/11 até 25/01 (hoje + 90 = 31/01): 12 ocorrências materializadas.
        $this->assertSame(12, $response->json('occurrences_created'));
        $this->assertSame('2027-01-25', Appointment::where('series_id', $response->json('data.series_id'))->max('appointment_date') !== null
            ? Carbon::parse(Appointment::where('series_id', $response->json('data.series_id'))->max('appointment_date'))->toDateString() : null);
    }

    public function test_conflicts_are_returned_per_occurrence_and_can_be_skipped_or_moved(): void
    {
        $this->appointmentAt('2026-11-16', '09:15');
        Holiday::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Consciência Negra', 'date' => '2026-11-23', 'recurring_annually' => false, 'active' => true]);

        $this->createSeries(['count' => 4])->assertStatus(409)
            ->assertJsonPath('conflicts.0.occurrence_index', 2)
            ->assertJsonPath('conflicts.0.reason', 'overlap')
            ->assertJsonPath('conflicts.0.starts_at', '2026-11-16T09:00')
            ->assertJsonPath('conflicts.1.occurrence_index', 3)
            ->assertJsonPath('conflicts.1.reason', 'holiday');
        $this->assertSame(0, AppointmentSeries::count());

        // Mover para um horário também ocupado devolve o conflito de novo.
        $this->createSeries(['count' => 4], [['occurrence_index' => 2, 'action' => 'skip'], ['occurrence_index' => 3, 'action' => 'move', 'starts_at' => '2026-11-16T09:30']])
            ->assertStatus(409)->assertJsonPath('conflicts.0.occurrence_index', 3)->assertJsonPath('conflicts.0.reason', 'overlap');

        $id = $this->createSeries(['count' => 4], [['occurrence_index' => 2, 'action' => 'skip'], ['occurrence_index' => 3, 'action' => 'move', 'starts_at' => '2026-11-24T10:00']])
            ->assertCreated()->assertJsonPath('occurrences_created', 3)->json('data.series_id');

        $byIndex = Appointment::where('series_id', $id)->orderBy('occurrence_index')->get()->keyBy('occurrence_index');
        $this->assertSame([1, 3, 4], $byIndex->keys()->all());
        $this->assertSame(['2026-11-24', '10:00', true], [$byIndex[3]->appointment_date->toDateString(), $byIndex[3]->appointment_time->format('H:i'), $byIndex[3]->is_exception]);

        // A pulada não volta com o job.
        $this->artisan('appointments:extend-series')->assertSuccessful();
        $this->assertSame(3, Appointment::where('series_id', $id)->count());
    }

    public function test_availability_conflict_when_the_time_is_outside_the_professional_schedule(): void
    {
        Availability::create(['professional_id' => $this->owner->id, 'day_of_week' => 1, 'start_time' => '08:00:00', 'end_time' => '12:00:00', 'slot_duration' => 30, 'buffer_time' => 0, 'is_active' => true]);

        $this->createSeries(['count' => 2], time: '14:00')->assertStatus(409)->assertJsonPath('conflicts.0.reason', 'availability')->assertJsonCount(2, 'conflicts');
        $this->createSeries(['count' => 2], time: '09:00')->assertCreated();
    }

    public function test_editing_only_this_marks_an_exception_and_this_and_following_splits_the_series(): void
    {
        $seriesId = $this->createSeries(['count' => 8])->json('data.series_id');
        $occ = fn (int $i): Appointment => Appointment::where('series_id', $seriesId)->where('occurrence_index', $i)->firstOrFail();

        $this->putOccurrence($occ(2), ['appointment_time' => '10:30'])->assertOk()->assertJsonPath('data.is_exception', true)->assertJsonPath('data.appointment_time', '10:30');
        $this->assertFalse($occ(3)->is_exception);
        // Mudança só de status não é exceção.
        $this->putOccurrence($occ(3), ['status' => 'confirmed'])->assertOk()->assertJsonPath('data.is_exception', false);

        $fifth = $occ(5);
        $response = $this->putOccurrence($fifth, ['appointment_time' => '15:00', 'scope' => 'this_and_following'])->assertOk()
            ->assertJsonPath('data.id', $fifth->id)->assertJsonPath('data.occurrence_index', 1)->assertJsonPath('data.appointment_time', '15:00')
            ->assertJsonPath('data.series.count', 4);
        $newSeriesId = $response->json('data.series_id');

        $this->assertNotSame($seriesId, $newSeriesId);
        $this->assertSame(4, AppointmentSeries::find($seriesId)->last_index);
        $this->assertSame(4, Appointment::where('series_id', $seriesId)->count());
        $this->assertSame(['15:00', '15:00', '15:00', '15:00'], Appointment::where('series_id', $newSeriesId)->orderBy('occurrence_index')->get()->map(fn ($a) => $a->appointment_time->format('H:i'))->all());
    }

    public function test_cancelling_only_this_and_this_and_following(): void
    {
        $seriesId = $this->createSeries(['count' => 6])->json('data.series_id');
        $occ = fn (int $i): Appointment => Appointment::withTrashed()->where('series_id', $seriesId)->where('occurrence_index', $i)->firstOrFail();

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/professional/appointments/{$occ(2)->id}?scope=this")->assertOk();
        $this->assertSoftDeleted('appointments', ['id' => $occ(2)->id]);

        $this->putOccurrence($occ(4), ['status' => 'cancelled', 'scope' => 'this_and_following'])->assertOk()->assertJsonPath('occurrences_affected', 3);
        $this->assertSame(['scheduled', 'scheduled', 'cancelled', 'cancelled', 'cancelled'], collect([1, 3, 4, 5, 6])->map(fn (int $i): string => $occ($i)->status)->all());
        $this->assertSame(3, AppointmentSeries::find($seriesId)->last_index);

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/professional/appointments/{$occ(3)->id}?scope=this_and_following")->assertOk();
        $this->assertSoftDeleted('appointments', ['id' => $occ(3)->id]);

        $this->artisan('appointments:extend-series')->assertSuccessful();
        $this->assertSame(1, Appointment::where('series_id', $seriesId)->where('status', 'scheduled')->count());
    }

    public function test_the_job_extends_the_window_without_duplicating(): void
    {
        $seriesId = $this->createSeries(['count' => 20])->json('data.series_id');
        $this->artisan('appointments:extend-series')->assertSuccessful();
        $this->assertSame(12, Appointment::where('series_id', $seriesId)->count());

        Carbon::setTestNow('2026-12-02 15:00:00');
        $this->artisan('appointments:extend-series')->assertSuccessful();
        $this->artisan('appointments:extend-series')->assertSuccessful();

        // Hoje + 90 = 02/03/2027: segundas até 01/03 → 17 ocorrências, cada índice uma vez.
        $this->assertSame(17, Appointment::where('series_id', $seriesId)->count());
        $this->assertSame(range(1, 17), Appointment::where('series_id', $seriesId)->orderBy('occurrence_index')->pluck('occurrence_index')->all());
    }

    public function test_package_session_is_consumed_when_an_occurrence_is_completed(): void
    {
        $bath = $this->makeService(['name' => 'Banho', 'price' => 60], by: $this->owner);
        $package = ServicePackage::create(['professional_id' => $this->owner->id, 'organization_id' => $this->clinic->id, 'name' => '4 banhos', 'price' => 200, 'validity_type' => 'unlimited', 'active' => true]);
        $package->items()->create(['service_id' => $bath->id, 'quantity' => 4]);
        $this->openRegisterFor($this->owner);
        $saleId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale', 'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'package', 'sellable_id' => $package->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->owner, 'cash'), 'amount' => 200])->assertCreated();
        $sold = SoldPackage::where('sale_id', $saleId)->firstOrFail();

        $seriesId = $this->createSeries(['count' => 4], extra: ['sold_package_id' => $sold->id, 'service_id' => $bath->id])->assertCreated()->json('data.series_id');
        $first = Appointment::where('series_id', $seriesId)->where('occurrence_index', 1)->firstOrFail();
        $this->assertSame($sold->id, $first->sold_package_id);

        foreach (['confirmed', 'in_progress', 'completed'] as $status) {
            $this->putOccurrence($first, ['status' => $status])->assertOk();
        }

        $this->assertSame(1, SoldPackageSession::where('status', 'consumed')->count());
        $this->assertSame($first->id, SoldPackageSession::where('status', 'consumed')->sole()->consumption->appointment_id);
    }

    public function test_other_organization_cannot_touch_the_series_and_the_recurring_filter(): void
    {
        $seriesId = $this->createSeries(['count' => 3])->json('data.series_id');
        $this->appointmentAt('2026-11-10', '09:00');

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/professional/appointments?recurring=1')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/professional/appointments')->assertOk()->assertJsonCount(4, 'data');

        $other = Organization::factory()->create();
        $stranger = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($other, 'organization')->create(['user_id' => $stranger->id]);
        $occurrence = Appointment::where('series_id', $seriesId)->firstOrFail();
        $this->actingAs($stranger, 'sanctum')->putJson("/api/professional/appointments/{$occurrence->id}", ['appointment_time' => '11:00', 'scope' => 'this_and_following'])->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->deleteJson("/api/professional/appointments/{$occurrence->id}?scope=this_and_following")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson('/api/professional/appointments?recurring=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_recurrence_validation(): void
    {
        $this->createSeries(['count' => 3, 'until' => '2026-12-31'])->assertUnprocessable()->assertJsonValidationErrors('recurrence');
        $this->createSeries([])->assertUnprocessable()->assertJsonValidationErrors('recurrence');
        $this->createSeries(['count' => 53])->assertUnprocessable()->assertJsonValidationErrors('recurrence.count');
        $this->createSeries(['until' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('recurrence.until');
        $this->createSeries(['count' => 2], [['occurrence_index' => 1, 'action' => 'move', 'starts_at' => '2026-11-10 10:00']])->assertUnprocessable()->assertJsonValidationErrors('resolutions.0.starts_at');
    }

    /**
     * @param  array<string, mixed>  $end  `until`/`count`
     * @param  list<array<string, mixed>>  $resolutions
     * @param  array<string, mixed>  $extra
     */
    private function createSeries(array $end, array $resolutions = [], string $time = '09:00', array $extra = []): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/appointments', [
            'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id, 'appointment_date' => '2026-11-09', 'appointment_time' => $time, 'type' => 'grooming',
            'duration' => 60, 'recurrence' => ['freq' => 'weekly', 'interval' => 1, 'weekdays' => [1]] + $end,
        ] + ($resolutions === [] ? [] : ['resolutions' => $resolutions]) + $extra);
    }

    private function appointmentAt(string $date, string $time): Appointment
    {
        return Appointment::create(['professional_id' => $this->owner->id, 'client_id' => $this->tutor->id, 'pet_id' => $this->pet->id,
            'appointment_date' => $date, 'appointment_time' => $time, 'duration' => 30, 'type' => 'grooming', 'status' => 'scheduled']);
    }

    /** @param  array<string, mixed>  $body */
    private function putOccurrence(Appointment $appointment, array $body): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->putJson("/api/professional/appointments/{$appointment->id}", $body);
    }
}
