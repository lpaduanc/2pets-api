<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\ReverseGeocodeRequest;
use App\Http\Resources\AddressLookupResource;
use App\Services\Location\AddressLookupService;

/**
 * Coordenada → endereço, para a modal de confirmação que o app mostra quando o usuário
 * autoriza a geolocalização do navegador.
 *
 * Público porque o site de marketing também consome (a busca da home pede a localização antes
 * de existir conta). O controle é o throttle nomeado `reverse-geocode`, deliberadamente bem
 * mais apertado que o da busca — ver `AppServiceProvider::registerRateLimiters()`.
 */
class ReverseGeocodeController extends Controller
{
    public function __construct(private readonly AddressLookupService $addressLookupService) {}

    /**
     * Nunca devolve 5xx para coordenada válida. "Não há endereço aqui" e "não consigo
     * consultar agora" são respostas 200 com `resolved: false` e `status` distintos — o
     * frontend precisa dos dois para escolher entre "confirme seu endereço" e o fallback de
     * mostrar a coordenada. Coordenada inválida continua 422, pelo Form Request.
     */
    public function __invoke(ReverseGeocodeRequest $request): AddressLookupResource
    {
        return new AddressLookupResource(
            $this->addressLookupService->forCoordinates($request->latitude(), $request->longitude())
        );
    }
}
