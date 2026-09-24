<?php

namespace App\Http\Resources\Concerns;

use App\Models\Professional;

/**
 * Localização de profissional na API PÚBLICA (cards da busca e perfil sem login).
 *
 * Profissional de ponto fixo (clínica, petshop...) publica o endereço comercial — é para lá
 * que o tutor vai. O VOLANTE (`Professional::isMobile()`) costuma operar do endereço
 * residencial, então dele só saem bairro/cidade/UF, coordenada com 2 casas (~1,1 km) e
 * distância em degraus de 500 m. O degrau não é cosmético: distância exata em metros,
 * consultada de três pontos diferentes, trilatera o endereço que a coordenada arredondada
 * tentou esconder.
 *
 * Quem usa precisa ter `professional` carregado e, na busca, `distance_km` projetado.
 */
trait PresentsPublicProfessionalLocation
{
    private const FIXED_COORDINATE_PRECISION = 3;

    private const MOBILE_COORDINATE_PRECISION = 2;

    private const MOBILE_DISTANCE_STEP_METERS = 500;

    /**
     * @return array<string, mixed>
     */
    protected function publicLocation(?Professional $professional): array
    {
        $isMobile = (bool) $professional?->isMobile();
        $precision = $isMobile ? self::MOBILE_COORDINATE_PRECISION : self::FIXED_COORDINATE_PRECISION;

        return [
            'address' => $isMobile ? null : $this->formatAddress(),
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'state' => $this->state,
            'is_mobile' => $isMobile,
            'latitude' => $this->roundedCoordinate($this->latitude, $precision),
            'longitude' => $this->roundedCoordinate($this->longitude, $precision),
            ...$this->publicDistance($isMobile),
        ];
    }

    /**
     * Zero não é falsy aqui: `isset` distingue "não calculado" (busca sem lat/lng, que
     * produz `NULL::double precision AS distance_km`) de "calculado, deu zero".
     *
     * @return array<string, float|int>
     */
    private function publicDistance(bool $isMobile): array
    {
        if (! isset($this->distance_km)) {
            return [];
        }

        $meters = (float) $this->distance_km * 1000;
        $publicMeters = $isMobile
            ? (int) (round($meters / self::MOBILE_DISTANCE_STEP_METERS) * self::MOBILE_DISTANCE_STEP_METERS)
            : (int) round($meters);

        return ['distance_m' => $publicMeters, 'distance_km' => round($publicMeters / 1000, 2)];
    }

    private function roundedCoordinate(mixed $coordinate, int $precision): ?float
    {
        return $coordinate === null ? null : round((float) $coordinate, $precision);
    }
}
