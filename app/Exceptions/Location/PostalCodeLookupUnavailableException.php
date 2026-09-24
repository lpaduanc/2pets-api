<?php

namespace App\Exceptions\Location;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Não deu para CONSULTAR — ViaCEP fora do ar, ou geocodificação sem provedor configurado.
 * Diferente de `PostalCodeNotFoundException`: o CEP pode ser válido, e o frontend oferece
 * "tente de novo" em vez de "confira o CEP".
 */
final class PostalCodeLookupUnavailableException extends RuntimeException
{
    private const MESSAGE = 'Não foi possível localizar este CEP agora. Tente novamente em instantes.';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGE);
    }

    public static function postalService(): self
    {
        return new self('postal_service_unavailable');
    }

    public static function geocoding(): self
    {
        return new self('geocoding_unavailable');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => self::MESSAGE, 'reason' => $this->reason], 503);
    }
}
