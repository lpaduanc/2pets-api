<?php

namespace App\Exceptions\Location;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * O CEP não existe (ViaCEP respondeu `erro`) ou não foi possível situá-lo no mapa nem no
 * nível da cidade. É erro do dado informado, não do sistema: 422 no campo `zip_code`, no
 * mesmo formato de erro de validação que o frontend já lê.
 */
final class PostalCodeNotFoundException extends RuntimeException
{
    private const MESSAGE = 'CEP não encontrado.';

    public function __construct(public readonly string $zipCode)
    {
        parent::__construct(self::MESSAGE);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => self::MESSAGE,
            'errors' => ['zip_code' => [self::MESSAGE]],
        ], 422);
    }
}
