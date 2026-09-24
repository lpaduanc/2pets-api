<?php

namespace App\DataTransferObjects\Location;

/**
 * Um CEP já situado no mapa: o endereço da ViaCEP mais a coordenada do geocoding.
 *
 * É a origem de uma busca por CEP — por isso carrega o rótulo curto ("Centro, Poços de
 * Caldas") que o frontend mostra em "Buscando perto de ...".
 */
final readonly class ResolvedPlace
{
    public function __construct(
        public PostalAddress $address,
        public float $latitude,
        public float $longitude,
    ) {}

    public function label(): string
    {
        return implode(', ', array_filter([$this->address->neighborhood, $this->address->city]));
    }

    /**
     * @return array{zip_code: string, street: ?string, neighborhood: ?string, city: string, state: string, latitude: float, longitude: float, label: string}
     */
    public function toArray(): array
    {
        return [
            'zip_code' => $this->address->zipCode,
            'street' => $this->address->street,
            'neighborhood' => $this->address->neighborhood,
            'city' => $this->address->city,
            'state' => $this->address->state,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'label' => $this->label(),
        ];
    }
}
