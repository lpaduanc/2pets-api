<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;

final class GeoLocationService
{
    /**
     * Calcula a distancia em metros entre dois pontos usando ST_Distance do PostGIS.
     * Usa geography(POINT, 4326) para calculo geodesico preciso (resultado em metros).
     */
    public function calculateDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $result = DB::selectOne(
            "SELECT ST_Distance(
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
            ) AS distance_meters",
            [$lng1, $lat1, $lng2, $lat2]
        );

        return (float) $result->distance_meters;
    }

    /**
     * Calcula a distancia em quilometros entre dois pontos.
     */
    public function calculateDistanceKm(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        return $this->calculateDistance($lat1, $lng1, $lat2, $lng2) / 1000;
    }

    /**
     * Verifica se dois pontos estao dentro de um raio usando ST_DWithin.
     * ST_DWithin com geography usa o indice GIST automaticamente.
     */
    public function isWithinRadius(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
        int $radiusKm
    ): bool {
        $radiusMeters = $radiusKm * 1000;

        $result = DB::selectOne(
            "SELECT ST_DWithin(
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                ?
            ) AS within",
            [$lng1, $lat1, $lng2, $lat2, $radiusMeters]
        );

        return (bool) $result->within;
    }

    /**
     * Gera a expressao SQL para criar um ponto geography a partir de lng/lat.
     * Retorna a expressao raw e os bindings separados para uso seguro com parameter binding.
     *
     * @return array{sql: string, bindings: array<int, float>}
     */
    public function makePointExpression(float $latitude, float $longitude): array
    {
        return [
            'sql' => "ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography",
            'bindings' => [$longitude, $latitude],
        ];
    }

    /**
     * Gera a expressao SQL para ST_DWithin usando a coluna location da tabela.
     * Ideal para WHERE clauses que aproveitam o indice GIST.
     *
     * @return array{sql: string, bindings: array<int, float|int>}
     */
    public function dWithinExpression(
        string $locationColumn,
        float $latitude,
        float $longitude,
        int $radiusKm
    ): array {
        $radiusMeters = $radiusKm * 1000;

        return [
            'sql' => "ST_DWithin({$locationColumn}, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)",
            'bindings' => [$longitude, $latitude, $radiusMeters],
        ];
    }

    /**
     * Gera a expressao SQL para ST_Distance retornando metros.
     *
     * @return array{sql: string, bindings: array<int, float>}
     */
    public function distanceExpression(
        string $locationColumn,
        float $latitude,
        float $longitude
    ): array {
        return [
            'sql' => "ST_Distance({$locationColumn}, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)",
            'bindings' => [$longitude, $latitude],
        ];
    }
}
