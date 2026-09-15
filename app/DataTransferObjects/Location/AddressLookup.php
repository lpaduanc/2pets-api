<?php

namespace App\DataTransferObjects\Location;

use App\Enums\Location\AddressLookupStatus;

/**
 * Resultado de uma consulta coordenada → endereço: o status mais, quando houve, os
 * componentes do endereço.
 *
 * Imutável, e sempre carrega de volta a coordenada consultada — o frontend precisa dela para
 * o fallback ("não consegui resolver, mas é aqui que você está") sem ter que lembrar o que
 * enviou.
 */
final readonly class AddressLookup
{
    /**
     * @param  array<string, string|null>|null  $address
     */
    private function __construct(
        public AddressLookupStatus $status,
        public float $latitude,
        public float $longitude,
        public ?array $address,
    ) {}

    /**
     * @param  array<string, string|null>  $address
     */
    public static function resolved(float $latitude, float $longitude, array $address): self
    {
        return new self(AddressLookupStatus::OK, $latitude, $longitude, $address);
    }

    public static function notFound(float $latitude, float $longitude): self
    {
        return new self(AddressLookupStatus::NOT_FOUND, $latitude, $longitude, null);
    }

    public static function unavailable(float $latitude, float $longitude): self
    {
        return new self(AddressLookupStatus::UNAVAILABLE, $latitude, $longitude, null);
    }
}
