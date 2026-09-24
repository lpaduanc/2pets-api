<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\Vaccination;
use App\Services\Dashboard\TutorActivityFeedService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Default e teto de `?activity_limit=` do feed "Atividade Recente" — sem teto, um tutor
     * antigo com anos de histórico poderia pedir um `LIMIT` gigante em 4 fontes de uma vez.
     */
    private const DEFAULT_ACTIVITY_LIMIT = 5;

    private const MAX_ACTIVITY_LIMIT = 20;

    public function __construct(private readonly TutorActivityFeedService $activityFeedService) {}

    /**
     * Get dashboard statistics and data for tutor
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;

        // Pets — the relation is `breedRelation`; the `breed` attribute on Pet is
        // already a denormalized string column with the breed name.
        $pets = $user->pets()
            ->orderBy('created_at', 'desc')
            ->limit(6)
            ->get()
            ->map(fn ($pet) => [
                'id' => $pet->id,
                'name' => $pet->name,
                'species' => $pet->species,
                'breed' => $pet->breed ?? '',
                'age' => $pet->birth_date ? $pet->birth_date->diffInYears(now()) : null,
                'image' => $pet->image_url ?? null,
                'gender' => $pet->gender,
            ]);

        $totalPets = $user->pets()->count();

        // Appointments — both counters read `appointments` for the same
        // client, so they are collected in one query instead of two.
        $appointmentCounters = $this->fetchAppointmentCounters($userId);
        $upcomingAppointments = $appointmentCounters['upcoming'];
        $todayAppointments = $appointmentCounters['today'];

        // Unread messages count. `messages` has no `receiver_id`/`is_read` column —
        // the receiver is whichever conversation participant isn't the sender, and
        // "unread" is `read_at IS NULL`. The previous query referenced columns that
        // don't exist, so it always threw and the try/catch silently reported 0.
        $unreadMessages = 0;
        try {
            $unreadMessages = DB::table('messages')
                ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where(function ($query) use ($userId) {
                    $query->where('conversations.participant_one_id', $userId)
                        ->orWhere('conversations.participant_two_id', $userId);
                })
                ->where('messages.sender_id', '!=', $userId)
                ->whereNull('messages.read_at')
                ->count();
        } catch (\Exception $e) {
            // table may not exist yet
        }

        // Health alerts
        $healthAlerts = $this->getHealthAlerts($userId);

        // Recent activity
        $recentActivity = $this->activityFeedService->recentActivity($user, $this->resolveActivityLimit($request));

        // Health score (percentage of up-to-date vaccines/dewormings)
        $healthScore = $this->calculateHealthScore($userId);

        return response()->json([
            'data' => [
                'pets' => $pets,
                'stats' => [
                    'totalPets' => $totalPets,
                    'upcomingAppointments' => $upcomingAppointments,
                    'todayAppointments' => $todayAppointments,
                    'unreadMessages' => $unreadMessages,
                    'healthScore' => $healthScore,
                ],
                'healthAlerts' => $healthAlerts,
                'recentActivity' => $recentActivity,
            ],
        ]);
    }

    /**
     * `whereDate()` casts `appointment_date` (a datetime column), which blocks
     * index usage — a half-open range over today is sargable and equivalent.
     *
     * @return array{upcoming: int, today: int}
     */
    private function fetchAppointmentCounters(int $userId): array
    {
        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $todayStart->copy()->addDay();

        $row = Appointment::where('client_id', $userId)
            ->selectRaw(
                "COUNT(*) FILTER (WHERE status != 'cancelled' AND appointment_date >= ?) AS upcoming,
                 COUNT(*) FILTER (WHERE status != 'cancelled' AND appointment_date >= ? AND appointment_date < ?) AS today",
                [$now, $todayStart, $todayEnd]
            )
            ->first();

        return [
            'upcoming' => (int) $row->upcoming,
            'today' => (int) $row->today,
        ];
    }

    /**
     * Carbon 3 devolve `diffInDays()` como float ("vence em 1.86 dias"): conta-se
     * em dias de calendário, do início de hoje ao início do vencimento.
     */
    private function formatDueIn(\DateTimeInterface $dueDate): string
    {
        $days = (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::instance($dueDate)->startOfDay());

        return match ($days) {
            0 => 'hoje',
            1 => 'amanhã',
            default => "em {$days} dias",
        };
    }

    private function getHealthAlerts(int $userId): array
    {
        $alerts = [];

        // Upcoming vaccines (next 7 days) — same append-only reasoning as the
        // overdue block below: a superseded dose must not surface an alert.
        $upcomingVaccines = Vaccination::whereHas('pet', fn ($q) => $q->where('user_id', $userId))
            ->latestPerType()
            ->whereNotNull('next_dose_date')
            ->whereBetween('next_dose_date', [now(), now()->addDays(7)])
            ->with('pet:id,name')
            ->limit(5)
            ->get();

        foreach ($upcomingVaccines as $v) {
            $alerts[] = [
                'type' => 'vaccine',
                'icon' => 'vaccines',
                'message' => "Vacina {$v->vaccine_name} de {$v->pet->name} vence ".$this->formatDueIn($v->next_dose_date),
                'date' => $v->next_dose_date->format('d/m/Y'),
                'severity' => 'warning',
            ];
        }

        // Overdue vaccines — only the latest dose of each type counts, since
        // vaccination history is append-only (see Vaccination::scopeLatestPerType).
        $overdueVaccines = Vaccination::whereHas('pet', fn ($q) => $q->where('user_id', $userId))
            ->latestPerType()
            ->whereNotNull('next_dose_date')
            ->where('next_dose_date', '<', now())
            ->with('pet:id,name')
            ->limit(5)
            ->get();

        foreach ($overdueVaccines as $v) {
            $alerts[] = [
                'type' => 'vaccine_overdue',
                'icon' => 'warning',
                'message' => "Vacina {$v->vaccine_name} de {$v->pet->name} está vencida!",
                'date' => $v->next_dose_date->format('d/m/Y'),
                'severity' => 'danger',
            ];
        }

        // Upcoming dewormings
        $upcomingDewormings = PetDeworming::whereHas('pet', fn ($q) => $q->where('user_id', $userId))
            ->whereNotNull('next_date')
            ->whereBetween('next_date', [now(), now()->addDays(7)])
            ->with('pet:id,name')
            ->limit(3)
            ->get();

        foreach ($upcomingDewormings as $d) {
            $alerts[] = [
                'type' => 'deworming',
                'icon' => 'medication',
                'message' => "Vermífugo de {$d->pet->name} vence ".$this->formatDueIn($d->next_date),
                'date' => $d->next_date->format('d/m/Y'),
                'severity' => 'warning',
            ];
        }

        return $alerts;
    }

    /**
     * `?activity_limit=` do feed "Atividade Recente" — valor fora da faixa cai no default,
     * nunca em erro 422 (é um parâmetro de exibição, não uma entrada validável de negócio).
     */
    private function resolveActivityLimit(Request $request): int
    {
        $requested = (int) $request->input('activity_limit', self::DEFAULT_ACTIVITY_LIMIT);
        if ($requested < 1) {
            return self::DEFAULT_ACTIVITY_LIMIT;
        }

        return min($requested, self::MAX_ACTIVITY_LIMIT);
    }

    private function calculateHealthScore(int $userId): int
    {
        $petIds = Pet::where('user_id', $userId)->pluck('id');

        if ($petIds->isEmpty()) {
            return 100;
        }

        $row = Vaccination::whereIn('pet_id', $petIds)
            ->latestPerType()
            ->whereNotNull('next_dose_date')
            ->selectRaw('COUNT(*) AS total, COUNT(*) FILTER (WHERE next_dose_date >= ?) AS up_to_date', [now()])
            ->first();

        $totalVaccines = (int) $row->total;

        if ($totalVaccines === 0) {
            return 100;
        }

        $upToDate = (int) $row->up_to_date;

        return (int) round(($upToDate / $totalVaccines) * 100);
    }
}
