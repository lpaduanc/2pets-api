<?php

namespace App\Services\Registration;

use App\Services\Location\GeocodingService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Endereço + geocodificação compartilhados pelos fluxos de conclusão de cadastro que
 * geocodificam (tutor, veterinário e profissional genérico — `completeCompany` não usa
 * esta classe, ver nota em `RegistrationCompletionService::completeCompany()`).
 *
 * Geocodificação é sempre best-effort: falha é logada e nunca interrompe o cadastro.
 */
final class RegistrationAddressResolver
{
    public function __construct(private readonly GeocodingService $geocodingService) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function addressData(array $data): array
    {
        return [
            'address' => $data['address'],
            'number' => $data['number'],
            'complement' => $data['complement'] ?? null,
            'neighborhood' => $data['neighborhood'],
            'city' => $data['city'],
            'state' => $data['state'],
            'zip_code' => $data['zip_code'],
        ];
    }

    /**
     * Prefere coordenadas do Google Places (capturadas no autocomplete — mais precisas que
     * re-geocodificar o endereço em texto livre). Cai para geocodificação no servidor.
     *
     * @param  array<string, mixed>  $data
     * @return array{latitude?: float, longitude?: float}
     */
    public function coordinatesPreferringRequest(array $data): array
    {
        if (isset($data['latitude'], $data['longitude'])) {
            return [
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
            ];
        }

        return $this->geocodedCoordinates($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{latitude?: float, longitude?: float}
     */
    public function geocodedCoordinates(array $data): array
    {
        return $this->geocode($data) ?? [];
    }

    /**
     * Monta o endereço completo a partir dos dados validados e geocodifica.
     *
     * @param  array<string, mixed>  $data
     * @return array{latitude: float, longitude: float}|null
     */
    private function geocode(array $data): ?array
    {
        try {
            $parts = array_filter([
                $data['address'] ?? null,
                $data['number'] ?? null,
                $data['neighborhood'] ?? null,
                $data['city'] ?? null,
                $data['state'] ?? null,
                $data['zip_code'] ?? null,
            ]);

            return $this->geocodingService->geocode(implode(', ', $parts));
        } catch (Throwable $e) {
            Log::warning('RegistrationAddressResolver: Geocoding failed, skipping coordinates', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
