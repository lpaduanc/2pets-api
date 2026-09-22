<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET professional/locations` (achado do frontend, item 21) — antes deste endpoint não
 * existia forma de listar as unidades da organização para alimentar o seletor `location_id`
 * de `professional/agenda/day`/`agenda/queue`. Só leitura: cadastro de unidade continua fora
 * do escopo desta rodada (não fazia parte de nenhum item do backlog gap-simplesvet).
 */
class LocationController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $locations = $this->scope->scopeQuery(Location::query(), $request->user())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return LocationResource::collection($locations);
    }
}
