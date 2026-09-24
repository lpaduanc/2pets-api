<?php

namespace App\Exceptions\Location;

use RuntimeException;

/**
 * O reprocessamento de `GeocodeUserAddress` ainda não resolveu o endereço. Lançada só para a
 * fila aplicar o `backoff` e tentar de novo — nunca chega a um usuário.
 */
final class GeocodingFailedException extends RuntimeException
{
    public static function forUser(int $userId): self
    {
        return new self("Geocoding do endereço do usuário {$userId} ainda não resolveu.");
    }
}
