<?php

namespace App\Services\Appointment\Recurrence;

use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Enums\RecurrenceFrequency;
use App\Models\Appointment;
use App\Models\AppointmentSeries;
use App\Models\User;
use App\Services\Appointment\AppointmentServicesWriter;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Séries de agendamento (docs/gap-petflow/PLANO.md, 3.1).
 *
 *  - `create()`: gera as datas (`RecurrenceDateGenerator`), confere CADA ocorrência
 *    (`OccurrenceConflictChecker`) antes de gravar qualquer coisa e, havendo conflito não
 *    resolvido, responde 409 com a lista; com `resolutions` (pular / mover para outro horário),
 *    grava a série e materializa as ocorrências até 90 dias à frente (data de negócio). Ocorrência
 *    movida nasce já como exceção, mesmo além da janela.
 *  - `materialize()`: cria as ocorrências que faltam até a janela — idempotente (por
 *    `occurrence_index`, inclusive apagadas), respeita as puladas e o fim da série. É o que o job
 *    diário chama. O conflito é conferido só na criação/divisão da série, não pelo job.
 *  - `truncateFrom()`: "este e os seguintes" cancelado/excluído — a série termina na anterior.
 *  - `splitFrom()`: "este e os seguintes" editado — a série velha termina na anterior e esta
 *    ocorrência vira a 1ª de uma série nova com as mudanças (e a regra nova, se vier).
 */
final class AppointmentSeriesService
{
    public const WINDOW_DAYS = 90;

    private const MOLD_FIELDS = ['client_id', 'pet_id', 'type', 'appointment_type_id', 'service_id', 'duration', 'price', 'reason', 'notes', 'sold_package_id'];

    public function __construct(
        private readonly RecurrenceDateGenerator $generator,
        private readonly OccurrenceConflictChecker $conflicts,
        private readonly AppointmentServicesWriter $servicesWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $data  campos do agendamento já validados (data/hora da 1ª ocorrência)
     * @param  array<string, mixed>  $recurrence
     * @param  list<array<string, mixed>>  $resolutions
     */
    public function create(User $professional, array $data, array $recurrence, array $resolutions): Appointment
    {
        $series = new AppointmentSeries($this->mold($data) + [
            'organization_id' => $data['organization_id'] ?? null,
            'professional_id' => $professional->id,
            'freq' => $recurrence['freq'],
            'interval' => (int) ($recurrence['interval'] ?? 1),
            'weekdays' => RecurrenceFrequency::from($recurrence['freq'])->usesWeekdays() ? array_values(array_map('intval', $recurrence['weekdays'] ?? [])) : null,
            'until' => $recurrence['until'] ?? null,
            'count' => isset($recurrence['count']) ? (int) $recurrence['count'] : null,
            'timezone' => BusinessClock::timezone(),
            'start_date' => CarbonImmutable::parse($data['appointment_date'])->toDateString(),
            'start_time' => $data['appointment_time'],
            'services' => $data['services'] ?? null,
            'created_by' => $professional->id,
        ]);

        return DB::transaction(function () use ($series, $resolutions): Appointment {
            $plan = $this->plan($series, $resolutions, []);
            $series->skipped_indices = $plan['skipped'];
            $series->save();

            return $this->persistPlan($series, $plan['moved']);
        });
    }

    /** Cria as ocorrências que faltam até hoje + 90 dias (data de negócio). Devolve quantas criou. */
    public function materialize(AppointmentSeries $series, ?CarbonImmutable $windowEnd = null): int
    {
        $windowEnd ??= BusinessClock::today()->addDays(self::WINDOW_DAYS);
        $existing = Appointment::withTrashed()->where('series_id', $series->id)->pluck('occurrence_index')->all();
        $skipped = $series->skipped_indices ?? [];
        $created = 0;

        foreach ($this->datesOf($series, $windowEnd) as $index => $date) {
            if (in_array($index, $existing, true) || in_array($index, $skipped, true) || ($series->last_index !== null && $index > $series->last_index)) {
                continue;
            }

            $this->createOccurrence($series, $index, CarbonImmutable::parse($date->toDateString().' '.$series->start_time), false);
            $created++;
        }

        $series->update(['materialized_until' => $windowEnd->toDateString()]);

        return $created;
    }

    /** "Este e os seguintes" cancelado (`cancel`) ou excluído: a série termina na ocorrência anterior. */
    public function truncateFrom(Appointment $occurrence, bool $cancel): int
    {
        return DB::transaction(function () use ($occurrence, $cancel): int {
            $series = $occurrence->series()->lockForUpdate()->firstOrFail();
            $series->update(['last_index' => $occurrence->occurrence_index - 1]);

            $following = $this->followingOpen($series, $occurrence->occurrence_index)->get();
            $following->each(fn (Appointment $a) => $cancel
                ? $a->update(['status' => AppointmentStatus::CANCELLED->value, 'cancelled_at' => now(), 'cancellation_reason' => 'Série encerrada a partir desta ocorrência'])
                : $a->delete());

            return $following->count();
        });
    }

    /**
     * "Este e os seguintes" editado. `$changes` são os campos do agendamento (data, hora, tipo…);
     * `$recurrence` é a regra nova (ou null = a mesma, com o que restava da contagem).
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>|null  $recurrence
     * @param  list<array<string, mixed>>  $resolutions
     */
    public function splitFrom(Appointment $occurrence, array $changes, ?array $recurrence, array $resolutions): Appointment
    {
        return DB::transaction(function () use ($occurrence, $changes, $recurrence, $resolutions): Appointment {
            $old = $occurrence->series()->lockForUpdate()->firstOrFail();
            $index = $occurrence->occurrence_index;
            $old->update(['last_index' => $index - 1]);
            $this->followingOpen($old, $index + 1)->get()->each(fn (Appointment $a) => $a->delete());

            $occurrence->fill(array_diff_key($changes, ['services' => true]));
            $new = $old->replicate(['skipped_indices', 'last_index', 'materialized_until']);
            $new->fill($this->mold($occurrence->only(self::MOLD_FIELDS)) + [
                'start_date' => $occurrence->appointment_date->toDateString(),
                'start_time' => $occurrence->appointment_time?->format('H:i') ?? $old->start_time,
                'services' => $changes['services'] ?? $old->services,
            ]);
            $this->applyRule($new, $old, $recurrence, $index);

            $plan = $this->plan($new, $resolutions, [$occurrence->id]);
            $new->skipped_indices = $plan['skipped'];
            $new->save();

            $occurrence->fill(['series_id' => $new->id, 'occurrence_index' => 1, 'is_exception' => false])->save();

            if (! empty($changes['services'])) {
                $occurrence->update(['price' => $this->servicesWriter->sync($occurrence, $changes['services'])]);
            }

            return $this->persistPlan($new, $plan['moved'], $occurrence);
        });
    }

    /**
     * Confere todas as ocorrências da série (até 52) e aplica as resoluções. Conflito não
     * resolvido → 409 `{message, conflicts}`.
     *
     * @param  list<array<string, mixed>>  $resolutions
     * @param  list<int>  $ignoreIds
     * @return array{skipped: list<int>, moved: array<int, CarbonImmutable>}
     */
    private function plan(AppointmentSeries $series, array $resolutions, array $ignoreIds): array
    {
        $byIndex = collect($resolutions)->keyBy(fn (array $r): int => (int) $r['occurrence_index']);
        $skipped = [];
        $moved = [];
        $conflicts = [];

        foreach ($this->datesOf($series, null) as $index => $date) {
            $resolution = $byIndex->get($index);

            if (($resolution['action'] ?? null) === 'skip') {
                $skipped[] = $index;

                continue;
            }

            $start = ($resolution['action'] ?? null) === 'move'
                ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $resolution['starts_at'])
                : CarbonImmutable::parse($date->toDateString().' '.$series->start_time);

            if ($resolution !== null) {
                $moved[$index] = $start;
            }

            // Na divisão, a 1ª ocorrência é o próprio agendamento já existente (`$ignoreIds`).
            $found = $this->conflicts->check($series->professional_id, $series->organization_id, $start, (int) ($series->duration ?? 30), $ignoreIds);

            if ($found !== null) {
                $conflicts[] = ['occurrence_index' => $index, 'starts_at' => $start->format('Y-m-d\TH:i')] + $found;
            }
        }

        if ($conflicts !== []) {
            throw new HttpResponseException(response()->json([
                'message' => 'Algumas ocorrências da série conflitam com a agenda. Escolha pular ou mover cada uma.',
                'conflicts' => $conflicts,
            ], 409));
        }

        return ['skipped' => $skipped, 'moved' => $moved];
    }

    /** @param  array<int, CarbonImmutable>  $moved */
    private function persistPlan(AppointmentSeries $series, array $moved, ?Appointment $first = null): Appointment
    {
        foreach ($moved as $index => $start) {
            if ($first !== null && $index === 1) {
                $first->fill(['appointment_date' => $start->toDateString(), 'appointment_time' => $start->format('H:i'), 'is_exception' => true])->save();

                continue;
            }

            $this->createOccurrence($series, $index, $start, true);
        }

        $this->materialize($series);

        return $first?->fresh() ?? Appointment::where('series_id', $series->id)->orderBy('occurrence_index')->firstOrFail();
    }

    private function createOccurrence(AppointmentSeries $series, int $index, CarbonImmutable $start, bool $exception): Appointment
    {
        $appointment = Appointment::create($series->only(self::MOLD_FIELDS) + [
            'professional_id' => $series->professional_id,
            'organization_id' => $series->organization_id,
            'appointment_date' => $start->toDateString(),
            'appointment_time' => $start->format('H:i'),
            'status' => AppointmentStatus::SCHEDULED->value,
            'booking_source' => BookingSource::PROFESSIONAL->value,
            'series_id' => $series->id,
            'occurrence_index' => $index,
            'is_exception' => $exception,
        ]);

        if (! empty($series->services)) {
            $appointment->update(['price' => $this->servicesWriter->sync($appointment, $series->services)]);
        }

        return $appointment;
    }

    /** @return array<int, CarbonImmutable> */
    private function datesOf(AppointmentSeries $series, ?CarbonImmutable $windowEnd): array
    {
        return $this->generator->dates($series->startDate(), $series->freq, $series->interval, $series->weekdays ?? [], $series->until === null ? null : CarbonImmutable::parse($series->until->toDateString()), $series->count, $windowEnd);
    }

    /** Ocorrências da série a partir do índice que ainda não aconteceram (agendadas/confirmadas/pendentes). */
    private function followingOpen(AppointmentSeries $series, int $fromIndex): \Illuminate\Database\Eloquent\Builder
    {
        return Appointment::query()->where('series_id', $series->id)->where('occurrence_index', '>=', $fromIndex)
            ->whereIn('status', [AppointmentStatus::SCHEDULED->value, AppointmentStatus::CONFIRMED->value, AppointmentStatus::PENDING->value]);
    }

    /** @param  array<string, mixed>|null  $recurrence */
    private function applyRule(AppointmentSeries $new, AppointmentSeries $old, ?array $recurrence, int $fromIndex): void
    {
        if ($recurrence !== null) {
            $new->fill([
                'freq' => $recurrence['freq'],
                'interval' => (int) ($recurrence['interval'] ?? 1),
                'weekdays' => RecurrenceFrequency::from($recurrence['freq'])->usesWeekdays() ? array_values(array_map('intval', $recurrence['weekdays'] ?? [])) : null,
                'until' => $recurrence['until'] ?? null,
                'count' => isset($recurrence['count']) ? (int) $recurrence['count'] : null,
            ]);

            return;
        }

        // Mesma regra: o que restava da contagem (a anterior já usou `$fromIndex − 1`).
        if ($old->count !== null) {
            $new->count = max(1, $old->count - ($fromIndex - 1));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mold(array $data): array
    {
        return array_intersect_key($data, array_flip(self::MOLD_FIELDS));
    }
}
