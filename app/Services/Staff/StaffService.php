<?php

namespace App\Services\Staff;

use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class StaffService
{
    public function createStaff(User $professional, User $user, array $data): Staff
    {
        $employeeId = $data['employee_id'] ?? $this->generateEmployeeId($professional);

        return Staff::create([
            'professional_id' => $professional->id,
            'user_id' => $user->id,
            'employee_id' => $employeeId,
            ...$data,
        ]);
    }

    public function updateSchedule(Staff $staff, string $dayOfWeek, array $scheduleData): StaffSchedule
    {
        return StaffSchedule::updateOrCreate(
            [
                'staff_id' => $staff->id,
                'day_of_week' => $dayOfWeek,
                'location_id' => $scheduleData['location_id'] ?? null,
            ],
            $scheduleData
        );
    }

    public function requestTimeOff(
        Staff $staff,
        string $type,
        \DateTime $startDate,
        \DateTime $endDate,
        ?string $reason = null
    ): StaffTimeOff {
        return StaffTimeOff::create([
            'staff_id' => $staff->id,
            'type' => $type,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => $reason,
            'status' => 'pending',
        ]);
    }

    public function getAvailableStaff(User $professional, \DateTime $date, ?int $locationId = null): Collection
    {
        $dayOfWeek = strtolower($date->format('l'));

        $query = Staff::where('professional_id', $professional->id)
            ->where('is_active', true)
            ->whereHas('schedules', function ($q) use ($dayOfWeek, $locationId) {
                $q->where('day_of_week', $dayOfWeek)
                  ->where('is_active', true);
                
                if ($locationId) {
                    $q->where('location_id', $locationId);
                }
            });

        $staff = $query->get();

        // Filter out staff on time off
        return $staff->filter(function ($member) use ($date) {
            return $member->isAvailableOn($date);
        });
    }

    public function getStaffPerformance(Staff $staff, ?\DateTime $startDate = null, ?\DateTime $endDate = null): array
    {
        $query = $staff->appointments()
            ->where('status', 'completed');

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

    public function getPendingTimeOffRequests(User $professional): Collection
    {
        return StaffTimeOff::whereHas('staff', function ($q) use ($professional) {
            $q->where('professional_id', $professional->id);
        })
        ->where('status', 'pending')
        ->with(['staff.user'])
        ->orderBy('created_at', 'desc')
        ->get();
    }

    private function generateEmployeeId(User $professional): string
    {
        $prefix = strtoupper(substr($professional->name, 0, 3));
        $number = Staff::where('professional_id', $professional->id)->count() + 1;
        return $prefix . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}

