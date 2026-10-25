<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\AddressSearchRequest;
use App\Http\Resources\AddressSearchResource;
use App\Services\Location\AddressSearchService;

/**
 * Autocomplete de endereço para o seletor de local em mapa do app do tutor (VPN/IP que
 * posiciona errado, tutor viajando com o pet). Cada sugestão já traz coordenada.
 *
 * Público pelo mesmo motivo do reverse geocoding; o controle é o throttle nomeado
 * `address-search` (ver `AppServiceProvider::registerRateLimiters()`).
 */
class AddressSearchController extends Controller
{
    public function __construct(private readonly AddressSearchService $addressSearchService) {}

    /**
     * Sempre 200 para texto válido — provedor fora do ar ou nenhum configurado é
     * `status: unavailable` com lista vazia, nunca 5xx. Texto inválido é 422 (Form Request).
     */
    public function __invoke(AddressSearchRequest $request): AddressSearchResource
    {
        return new AddressSearchResource($this->addressSearchService->search($request->toQuery()));
    }
}
