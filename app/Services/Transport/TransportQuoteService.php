<?php

namespace App\Services\Transport;

use App\Models\Organization;
use App\Models\ServiceArea;
use App\Services\Location\GeocodingService;
use App\Support\GeoDistance;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Cotação da taxa do leva e traz (PLANO 3.7): primeiro a zona por CEP; se nenhuma casar, a
 * distância em linha reta (Haversine) da clínica até o endereço, com a geocodificação que já
 * existe (`GeocodingService`, cache de 30 dias). Nenhuma zona → 422 "fora da área".
 *
 * Coordenada da clínica: a gravada na organização; sem ela, o endereço da organização é
 * geocodificado (e cacheado pelo serviço). Sem organização (profissional volante) só a regra por
 * CEP funciona — não há "endereço da clínica" para medir.
 */
final class TransportQuoteService
{
    public const OUT_OF_AREA = 'Endereço fora da área do leva e traz.';

    public function __construct(
        private readonly DeliveryZoneMatcher $matcher,
        private readonly GeocodingService $geocoding,
    ) {}

    /**
     * @param  array{zip_code?: string|null, address?: string|null, complement?: string|null, city?: string|null, state?: string|null}  $address
     * @return array{zone: ServiceArea, distance_km: string|null, fee: string}
     */
    public function quote(?int $organizationId, int $professionalId, array $address): array
    {
        $zones = $this->zones($organizationId, $professionalId);
        $byCep = $this->matcher->byCep($zones, (string) ($address['zip_code'] ?? ''));

        if ($byCep !== null) {
            return ['zone' => $byCep, 'distance_km' => null, 'fee' => Money::of($byCep->delivery_fee ?? 0)->toString()];
        }

        $distance = $this->distance($organizationId, $address);
        $byDistance = $distance === null ? null : $this->matcher->byDistance($zones, $distance);

        if ($byDistance === null) {
            throw ValidationException::withMessages(['address' => self::OUT_OF_AREA]);
        }

        return ['zone' => $byDistance, 'distance_km' => number_format($distance, 2, '.', ''), 'fee' => Money::of($byDistance->delivery_fee ?? 0)->toString()];
    }

    /** @return Collection<int, ServiceArea> */
    private function zones(?int $organizationId, int $professionalId): Collection
    {
        return ServiceArea::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId), fn ($q) => $q->whereNull('organization_id')->where('professional_id', $professionalId))
            ->where('active', true)->where('delivery_enabled', true)
            ->get();
    }

    /** @param  array<string, string|null>  $address */
    private function distance(?int $organizationId, array $address): ?float
    {
        $organization = $organizationId === null ? null : Organization::find($organizationId);
        $origin = $organization === null ? null : $this->origin($organization);
        $text = self::addressText($address);
        $target = $origin === null || $text === '' ? null : $this->geocoding->geocode($text);

        if ($origin === null || $target === null) {
            return null;
        }

        return round(GeoDistance::km($origin['latitude'], $origin['longitude'], $target['latitude'], $target['longitude']), 2);
    }

    /** @return array{latitude: float, longitude: float}|null */
    private function origin(Organization $organization): ?array
    {
        if ($organization->latitude !== null && $organization->longitude !== null) {
            return ['latitude' => (float) $organization->latitude, 'longitude' => (float) $organization->longitude];
        }

        return $this->geocoding->geocode(self::addressText([
            'address' => trim(implode(', ', array_filter([$organization->address, $organization->number, $organization->neighborhood]))),
            'city' => $organization->city, 'state' => $organization->state, 'zip_code' => $organization->zip_code,
        ]));
    }

    /** @param  array<string, string|null>  $address */
    public static function addressText(array $address): string
    {
        $parts = array_filter([
            $address['address'] ?? null,
            trim(implode(' - ', array_filter([$address['city'] ?? null, $address['state'] ?? null]))),
            $address['zip_code'] ?? null,
        ], fn ($part): bool => $part !== null && $part !== '');

        return $parts === [] ? '' : implode(', ', $parts).', Brasil';
    }
}
