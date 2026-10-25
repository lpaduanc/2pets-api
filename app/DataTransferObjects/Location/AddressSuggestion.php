<?php

namespace App\DataTransferObjects\Location;

/**
 * Um item do autocomplete de endereço, já com coordenada — o seletor de mapa centraliza nele
 * sem segunda consulta. Provedor que não traz coordenada não gera item (ver `fromComponents`).
 *
 * `title`/`subtitle` são montados aqui, e não em cada provedor, para que Google e Photon
 * apareçam iguais na lista: "Rua das Acácias, 120" / "Vila Mariana, São Paulo - SP".
 */
final readonly class AddressSuggestion
{
    /** Componentes que o contrato expõe, na ordem da resposta. */
    private const ADDRESS_KEYS = ['formatted', 'street', 'number', 'neighborhood', 'city', 'state', 'state_code', 'zip_code'];

    /**
     * @param  array<string, ?string>  $components
     * @param  array{latitude: float, longitude: float}  $coordinates
     */
    private function __construct(
        public string $id,
        public string $title,
        private array $components,
        private array $coordinates,
    ) {}

    /**
     * @param  array{name?: ?string, formatted?: ?string, street?: ?string, number?: ?string, neighborhood?: ?string, city?: ?string, state?: ?string, state_code?: ?string, zip_code?: ?string}  $components
     */
    public static function fromComponents(string $id, array $components, ?float $latitude, ?float $longitude): ?self
    {
        $title = self::title($components);

        if ($latitude === null || $longitude === null || $title === null) {
            return null;
        }

        return new self($id, $title, $components, ['latitude' => $latitude, 'longitude' => $longitude]);
    }

    /**
     * Duas sugestões com o mesmo título e subtítulo são o mesmo endereço PARA O TUTOR, ainda
     * que o provedor as tenha como objetos diferentes (trechos de uma rua no OSM).
     */
    public function deduplicationKey(): string
    {
        return mb_strtolower(trim($this->title).'|'.trim((string) self::subtitle($this->components)));
    }

    /**
     * @return array<string, string|float|null>
     */
    public function toArray(): array
    {
        $address = array_map(fn (string $key): ?string => $this->components[$key] ?? null, array_combine(self::ADDRESS_KEYS, self::ADDRESS_KEYS));

        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => self::subtitle($this->components),
            ...$address,
            ...$this->coordinates,
        ];
    }

    /**
     * Rua e número; sem rua (um bairro, uma cidade, um ponto de interesse), o nome do lugar.
     *
     * @param  array<string, ?string>  $components
     */
    private static function title(array $components): ?string
    {
        $street = self::join(', ', [$components['street'] ?? null, $components['number'] ?? null]);

        return $street ?? self::join(', ', [$components['name'] ?? null, $components['city'] ?? null]);
    }

    /**
     * @param  array<string, ?string>  $components
     */
    private static function subtitle(array $components): ?string
    {
        $cityAndState = self::join(' - ', [$components['city'] ?? null, $components['state_code'] ?? $components['state'] ?? null]);

        return self::join(', ', [$components['neighborhood'] ?? null, $cityAndState]);
    }

    /**
     * @param  list<?string>  $parts
     */
    private static function join(string $separator, array $parts): ?string
    {
        $present = array_filter($parts, fn (?string $part): bool => $part !== null && trim($part) !== '');

        return $present === [] ? null : implode($separator, array_unique($present));
    }
}
