<?php

namespace App\Services\Staff;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\StaffSchedule;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sem rota nenhuma usando este service hoje (dívida pré-existente). Ajustado na Fase 1 do
 * split Pessoa/Organização apenas para continuar compilando e coerente com o novo schema:
 * `professional_id` (dono/empregador, que era um `User`) virou `organization_id` — o vínculo
 * agora é sempre com uma `Organization`, nunca com a pessoa que a administra.
 */
final class StaffService
{
    public function createStaff(Organization $organization, User $user, array $data): OrganizationMember
    {
        $employeeId = $data['employee_id'] ?? $this->generateEmployeeId($organization);

        return OrganizationMember::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'employee_id' => $employeeId,
            ...$data,
        ]);
    }

    public function updateSchedule(OrganizationMember $member, string $dayOfWeek, array $scheduleData): StaffSchedule
    {
        return StaffSchedule::updateOrCreate(
            [
                'staff_id' => $member->id,
                'day_of_week' => $dayOfWeek,
                'location_id' => $scheduleData['location_id'] ?? null,
            ],
            $scheduleData
        );
    }

    public function requestTimeOff(
        OrganizationMember $member,
        string $type,
        \DateTime $startDate,
        \DateTime $endDate,
        ?string $reason = null
    ): StaffTimeOff {
        return StaffTimeOff::create([
            'staff_id' => $member->id,
            'type' => $type,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => $reason,
            'status' => 'pending',
        ]);
    }

    public function getAvailableStaff(Organization $organization, \DateTime $date, ?int $locationId = null): Collection
    {
        $dayOfWeek = strtolower($date->format('l'));

        $members = OrganizationMember::where('organization_id', $organization->id)
            ->where('is_active', true)
            ->whereHas('schedules', function ($query) use ($dayOfWeek, $locationId): void {
                $query->where('day_of_week', $dayOfWeek)->where('is_active', true);

                if ($locationId) {
                    $query->where('location_id', $locationId);
                }
            })
            ->get();

        return $members->filter(fn (OrganizationMember $member): bool => $member->isAvailableOn($date));
    }

    public function getStaffPerformance(OrganizationMember $member, ?\DateTime $startDate = null, ?\DateTime $endDate = null): array
    {
        $query = $member->appointments()->where('status', 'completed');

        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }

        $appointments = $query->get();
        $totalAppointments = $appointments->count();
        $totalRevenue = $appointments->sum('total_amount');

        return [
            'total_appointments' => $totalAppointments,
            'total_revenue' => (float) $totalRevenue,
            'average_per_appointment' => $totalAppointments > 0 ? $totalRevenue / $totalAppointments : 0,
        ];
    }

    public function getPendingTimeOffRequests(Organization $organization): Collection
    {
        return StaffTimeOff::whereHas('staff', function ($query) use ($organization): void {
            $query->where('organization_id', $organization->id);
        })
            ->where('status', 'pending')
            ->with(['staff.user'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    private function generateEmployeeId(Organization $organization): string
    {
        $prefix = strtoupper(substr($organization->business_name ?? 'ORG', 0, 3));
        $number = OrganizationMember::where('organization_id', $organization->id)->count() + 1;

        return $prefix.'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}
