<?php

namespace App\Services\Location;

use App\Enums\Location\GeocodingStatus;
use App\Jobs\GeocodeUserAddress;
use App\Models\User;

/**
 * Endereço do usuário → atributos de coordenada (`latitude`, `longitude`,
 * `geocoding_status`, `geocoded_at`) prontos para gravar JUNTO com o endereço.
 *
 * Regra do produto: o ponto do profissional é geocodificado UMA vez, no cadastro/edição do
 * endereço — nunca na busca. Se o geocoding falhar, o endereço é salvo mesmo assim, a
 * coordenada é ANULADA (nunca fica o ponto do endereço antigo) e o status vira `failed`,
 * para `GeocodeUserAddress` reprocessar depois.
 *
 * Único lugar que monta o texto enviado ao provedor — antes, cadastro e edição de perfil
 * montavam cada um o seu, e a edição esquecia número e bairro.
 */
final class UserAddressGeocoder
{
    /** @var list<string> */
    public const ADDRESS_FIELDS = ['address', 'number', 'neighborhood', 'city', 'state', 'zip_code'];

    public function __construct(private readonly GeocodingService $geocodingService) {}

    public function isConfigured(): bool
    {
        return $this->geocodingService->isConfigured();
    }

    /**
     * @param  array<string, mixed>  $address  chaves de `ADDRESS_FIELDS`
     * @return array{latitude: ?float, longitude: ?float, geocoding_status: ?GeocodingStatus, geocoded_at: ?\Illuminate\Support\Carbon}
     */
    public function coordinateAttributes(array $address): array
    {
        if (blank($address['city'] ?? null)) {
            return $this->attributes(null, null, null);
        }

        $coordinates = $this->firstCoordinates($address);

        return $coordinates === null
            ? $this->attributes(null, null, GeocodingStatus::FAILED)
            : $this->resolvedAttributes($coordinates['latitude'], $coordinates['longitude']);
    }

    /**
     * Coordenada que o próprio cliente já trouxe (autocomplete do Google Places no cadastro
     * do tutor) — mais precisa que re-geocodificar o texto.
     *
     * @return array{latitude: float, longitude: float, geocoding_status: GeocodingStatus, geocoded_at: ?\Illuminate\Support\Carbon}
     */
    public function resolvedAttributes(float $latitude, float $longitude): array
    {
        return $this->attributes($latitude, $longitude, GeocodingStatus::RESOLVED);
    }

    /**
     * Reprocessa o endereço já gravado. Devolve se resolveu.
     */
    public function geocodeStoredAddress(User $user): bool
    {
        $attributes = $this->coordinateAttributes($user->only(self::ADDRESS_FIELDS));

        $user->update($attributes);

        return $attributes['geocoding_status'] === GeocodingStatus::RESOLVED;
    }

    /**
     * Enfileira o reprocessamento depois do commit — só quando há provedor configurado. Sem
     * chave, a fila só repetiria a mesma falha; `geocoding:retry-failed` recolhe essas contas
     * quando o provedor voltar.
     */
    public function scheduleRetryIfFailed(User $user): void
    {
        if ($user->geocoding_status !== GeocodingStatus::FAILED || ! $this->isConfigured()) {
            return;
        }

        GeocodeUserAddress::dispatch($user->id)->afterCommit();
    }

    /**
     * Tenta o endereço completo e, se não resolver, o mesmo endereço SEM o bairro.
     *
     * O bairro digitado pelo usuário nem sempre é o nome que o mapa conhece — em Poços de
     * Caldas o OpenStreetMap usa "Região Urbana Homogênea IX" onde o morador escreve
     * "Funcionários", e o Nominatim devolve zero resultado para a busca inteira. Rua, número,
     * cidade e UF bastam para achar o ponto. A segunda consulta só sai quando a primeira falha.
     *
     * @param  array<string, mixed>  $address
     * @return array{latitude: float, longitude: float}|null
     */
    private function firstCoordinates(array $address): ?array
    {
        $coordinates = $this->geocodingService->geocode($this->fullAddress($address));

        if ($coordinates !== null || blank($address['neighborhood'] ?? null)) {
            return $coordinates;
        }

        return $this->geocodingService->geocode($this->fullAddress([...$address, 'neighborhood' => null]));
    }

    /**
     * "Rua X, 123, Bairro, Cidade - UF, 37701-000, Brasil" — do específico ao geral.
     *
     * @param  array<string, mixed>  $address
     */
    private function fullAddress(array $address): string
    {
        $street = implode(', ', array_filter([$address['address'] ?? null, $address['number'] ?? null]));
        $cityAndState = implode(' - ', array_filter([$address['city'] ?? null, $address['state'] ?? null]));

        return implode(', ', array_filter([
            $street,
            $address['neighborhood'] ?? null,
            $cityAndState,
            $address['zip_code'] ?? null,
            'Brasil',
        ]));
    }

    /**
     * @return array{latitude: ?float, longitude: ?float, geocoding_status: ?GeocodingStatus, geocoded_at: ?\Illuminate\Support\Carbon}
     */
    private function attributes(?float $latitude, ?float $longitude, ?GeocodingStatus $status): array
    {
        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'geocoding_status' => $status,
            'geocoded_at' => $status === GeocodingStatus::RESOLVED ? now() : null,
        ];
    }
}
