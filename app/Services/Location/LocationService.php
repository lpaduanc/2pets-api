<?php

namespace App\Services\Location;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LocationService
{
    public function createLocation(User $professional, array $data): Location
    {
        return DB::transaction(function () use ($professional, $data) {
            // If this is marked as primary, unset other primary locations
            if ($data['is_primary'] ?? false) {
                Location::where('professional_id', $professional->id)
                    ->update(['is_primary' => false]);
            }

            return Location::create([
                'professional_id' => $professional->id,
                ...$data,
            ]);
        });
    }

    public function updateLocation(Location $location, array $data): Location
    {
        return DB::transaction(function () use ($location, $data) {
            // If setting this as primary, unset other primary locations
            if (($data['is_primary'] ?? false) && !$location->is_primary) {
                Location::where('professional_id', $location->professional_id)
                    ->where('id', '!=', $location->id)
                    ->update(['is_primary' => false]);
            }

            $location->update($data);
            return $location->fresh();
        });
    }

    public function assignStaff(Location $location, User $staff, string $role): void
    {
        $location->staff()->syncWithoutDetaching([
            $staff->id => [
                'role' => $role,
                'is_active' => true,
            ]
        ]);
    }

    public function removeStaff(Location $location, User $staff): void
    {
        $location->staff()->detach($staff->id);
    }

    public function getActiveLocations(User $professional): Collection
    {
        return Location::where('professional_id', $professional->id)
            ->where('is_active', true)
            ->orderBy('is_primary', 'desc')
            ->orderBy('name')
            ->get();
    }

    public function getPrimaryLocation(User $professional): ?Location
    {
        return Location::where('professional_id', $professional->id)
            ->where('is_primary', true)
            ->where('is_active', true)
            ->first();
    }

    public function getLocationStats(Location $location): array
    {
        $totalAppointments = $location->appointments()->count();
        $todayAppointments = $location->appointments()
            ->whereDate('date', today())
            ->count();
        
        $revenue = $location->appointments()
            ->where('status', 'completed')
            ->sum('total_amount');

        $staffCount = $location->staff()
            ->wherePivot('is_active', true)
            ->count();

        return [
            'total_appointments' => $totalAppointments,
            'today_appointments' => $todayAppointments,
            'total_revenue' => $revenue,
            'active_staff' => $staffCount,
            'services_count' => $location->services()->count(),
            'is_open_now' => $location->isOpenNow(),
        ];
    }

    public function getNearbyLocations(float $latitude, float $longitude, float $radiusKm = 10): Collection
    {
        // Using Haversine formula to calculate distance
        return Location::select('locations.*')
            ->selectRaw('
                (6371 * acos(
                    cos(radians(?)) * 
                    cos(radians(latitude)) * 
                    cos(radians(longitude) - radians(?)) + 
                    sin(radians(?)) * 
                    sin(radians(latitude))
                )) AS distance
            ', [$latitude, $longitude, $latitude])
            ->where('is_active', true)
            ->having('distance', '<=', $radiusKm)
            ->orderBy('distance')
            ->get();
    }
}

