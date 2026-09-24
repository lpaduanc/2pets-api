<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\UpdateSearchLocationRequest;
use App\Http\Resources\SearchLocationResource;
use App\Services\Location\SearchLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Última localização confirmada para a busca (`/api/me/search-location`) — sempre do
 * próprio usuário autenticado, sem id na rota, logo sem superfície de IDOR.
 */
class SearchLocationController extends Controller
{
    public function __construct(private readonly SearchLocationService $searchLocationService) {}

    /** `{"data": null}` quando o usuário ainda não confirmou nenhuma localização. */
    public function show(Request $request): SearchLocationResource|JsonResponse
    {
        $location = $this->searchLocationService->current($request->user());

        return $location === null
            ? response()->json(['data' => null])
            : new SearchLocationResource($location);
    }

    /**
     * Upsert: 200 sempre. Sem o status explícito, o `JsonResource` responderia 201 na
     * primeira gravação (`wasRecentlyCreated`) e 200 nas seguintes para o mesmo PUT.
     */
    public function update(UpdateSearchLocationRequest $request): JsonResponse
    {
        $location = $this->searchLocationService->remember($request->user(), $request->validated());

        return (new SearchLocationResource($location))->response()->setStatusCode(200);
    }
}
