<?php

namespace App\Services\Location;

use App\Models\User;
use App\Models\UserSearchLocation;

/**
 * Lembra a última localização que o usuário confirmou para buscar (GPS ou CEP), para servir
 * de padrão na próxima visita — em qualquer aparelho, não só no localStorage de um.
 */
final class SearchLocationService
{
    /**
     * ~110 m: basta para a busca por proximidade e não guarda a posição exata do tutor
     * (minimização da LGPD).
     */
    private const STORED_COORDINATE_PRECISION = 3;

    public function current(User $user): ?UserSearchLocation
    {
        return UserSearchLocation::query()->where('user_id', $user->id)->first();
    }

    /**
     * @param  array<string, mixed>  $location  validado por `UpdateSearchLocationRequest`
     */
    public function remember(User $user, array $location): UserSearchLocation
    {
        return UserSearchLocation::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'source' => $location['source'],
                'label' => $location['label'] ?? null,
                'zip_code' => isset($location['zip_code']) ? preg_replace('/\D/', '', $location['zip_code']) : null,
                'neighborhood' => $location['neighborhood'] ?? null,
                'city' => $location['city'] ?? null,
                'state' => isset($location['state']) ? mb_strtoupper($location['state']) : null,
                'latitude' => round((float) $location['latitude'], self::STORED_COORDINATE_PRECISION),
                'longitude' => round((float) $location['longitude'], self::STORED_COORDINATE_PRECISION),
            ]
        );
    }
}
