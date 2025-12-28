<?php

namespace App\Services\Search;

final class GeoLocationService
{
    private const EARTH_RADIUS_KM = 6371;

    public function calculateDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lngDelta / 2) * sin($lngDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }

    public function isWithinRadius(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
        int $radiusKm
    ): bool {
        $distance = $this->calculateDistance($lat1, $lng1, $lat2, $lng2);

        return $distance <= $radiusKm;
    }
}
