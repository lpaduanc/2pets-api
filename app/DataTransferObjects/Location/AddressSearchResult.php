<?php

namespace App\DataTransferObjects\Location;

use App\Enums\Location\AddressLookupStatus;

/**
 * Resultado do autocomplete: o status (mesmos três de `AddressLookupStatus` — achei, não
 * existe, não consigo consultar) e as sugestões, vazias sempre que o status não é `ok`.
 */
final readonly class AddressSearchResult
{
    /**
     * @param  list<AddressSuggestion>  $suggestions
     */
    private function __construct(
        public AddressLookupStatus $status,
        public array $suggestions,
    ) {}

    /**
     * @param  list<AddressSuggestion>  $suggestions
     */
    public static function found(array $suggestions): self
    {
        return new self(AddressLookupStatus::OK, $suggestions);
    }

    public static function notFound(): self
    {
        return new self(AddressLookupStatus::NOT_FOUND, []);
    }

    public static function unavailable(): self
    {
        return new self(AddressLookupStatus::UNAVAILABLE, []);
    }
}
