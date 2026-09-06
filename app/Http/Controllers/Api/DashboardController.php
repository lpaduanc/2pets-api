<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\Vaccination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
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
        $recentActivity = $this->getRecentActivity($userId);

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

    private function getHealthAlerts(int $userId): array
    {
        $alerts = [];

        // Upcoming vaccines (next 7 days)
        $upcomingVaccines = Vaccination::whereHas('pet', fn ($q) => $q->where('user_id', $userId))
            ->whereNotNull('next_dose_date')
            ->whereBetween('next_dose_date', [now(), now()->addDays(7)])
            ->with('pet:id,name')
            ->limit(5)
            ->get();

        foreach ($upcomingVaccines as $v) {
            $alerts[] = [
                'type' => 'vaccine',
                'icon' => 'vaccines',
                'message' => "Vacina {$v->vaccine_name} de {$v->pet->name} vence em ".now()->diffInDays($v->next_dose_date).' dias',
                'date' => $v->next_dose_date->format('d/m/Y'),
                'severity' => 'warning',
            ];
        }

        // Overdue vaccines
        $overdueVaccines = Vaccination::whereHas('pet', fn ($q) => $q->where('user_id', $userId))
            ->whereNotNull('next_dose_date')
            ->where('next_dose_date', '<', now())
            ->with('pet:id,name')
            ->limit(5)
            ->get();

        foreach ($overdueVaccines as $v) {
            $alerts[] = [
                'type' => 'vaccine_overdue',
                'icon' => 'warning',
                'message' => "Vacina {$v->vaccine_name} de {$v->pet->name} esta vencida!",
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
                'message' => "Vermifugo de {$d->pet->name} vence em ".now()->diffInDays($d->next_date).' dias',
                'date' => $d->next_date->format('d/m/Y'),
                'severity' => 'warning',
            ];
        }

        return $alerts;
    }

    private function getRecentActivity(int $userId): array
    {
        $activities = [];

        // Recent appointments
        $recentAppts = Appointment::where('client_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();

        foreach ($recentAppts as $appt) {
            $activities[] = [
                'type' => 'appointment',
                'icon' => 'event',
                'title' => 'Consulta '.($appt->status === 'confirmed' ? 'confirmada' : ($appt->status === 'pending' ? 'agendada' : $appt->status)),
                'description' => $appt->service_name ?? 'Consulta',
                'time' => $appt->created_at->diffForHumans(),
                'created_at' => $appt->created_at->toISOString(),
            ];
        }

        // Recent notifications (from DB)
        try {
            $notifications = DB::table('notifications')
                ->where('notifiable_id', $userId)
                ->where('notifiable_type', 'App\\Models\\User')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();

            foreach ($notifications as $notif) {
                $data = json_decode($notif->data, true);
                $activities[] = [
                    'type' => $data['type'] ?? 'notification',
                    'icon' => $this->getActivityIcon($data['type'] ?? ''),
                    'title' => $data['title'] ?? 'Notificacao',
                    'description' => $data['message'] ?? '',
                    'time' => \Carbon\Carbon::parse($notif->created_at)->diffForHumans(),
                    'created_at' => $notif->created_at,
                ];
            }
        } catch (\Exception $e) {
            // notifications table may not exist yet
        }

        // Sort by time and limit
        usort($activities, fn ($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));

        return array_slice($activities, 0, 5);
    }

    private function calculateHealthScore(int $userId): int
    {
        $petIds = Pet::where('user_id', $userId)->pluck('id');

        if ($petIds->isEmpty()) {
            return 100;
        }

        $row = Vaccination::whereIn('pet_id', $petIds)
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

    private function getActivityIcon(string $type): string
    {
        return match ($type) {
            'vaccine_reminder', 'vaccine_overdue' => 'vaccines',
            'deworming_reminder' => 'medication',
            'payment_confirmed' => 'payments',
            'payment_failed' => 'error',
            'review_published' => 'star',
            'welcome' => 'celebration',
            default => 'notifications',
        };
    }
}
