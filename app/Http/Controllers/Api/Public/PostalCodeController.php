<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\PostalCodeLookupRequest;
use App\Http\Resources\ResolvedPlaceResource;
use App\Services\Location\PostalCodeLocator;

/**
 * `GET /api/public/postal-code/{zipCode}` — fallback de localização da busca: quando o
 * aparelho não dá a posição (permissão negada, timeout, desktop sem GPS), o tutor informa o
 * CEP e recebe a coordenada + "bairro, cidade" para seguir exatamente o mesmo fluxo.
 *
 * Erros viram resposta pelas próprias exceções: 422 para CEP inexistente
 * (`PostalCodeNotFoundException`), 503 para consulta indisponível
 * (`PostalCodeLookupUnavailableException`).
 */
class PostalCodeController extends Controller
{
    public function __construct(private readonly PostalCodeLocator $postalCodeLocator) {}

    public function __invoke(PostalCodeLookupRequest $request): ResolvedPlaceResource
    {
        return new ResolvedPlaceResource($this->postalCodeLocator->locate($request->zipCode()));
    }
}
