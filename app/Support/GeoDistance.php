<?php

namespace App\Support;

/** Distância em linha reta (Haversine) entre duas coordenadas, em km — PLANO 3.7. */
final class GeoDistance
{
    private const EARTH_RADIUS_KM = 6371.0;

    public static function km(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
