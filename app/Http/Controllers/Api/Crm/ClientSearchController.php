<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Http\Resources\Crm\ClientRelationshipProfileResource;
use App\Models\ClientRelationshipProfile;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Crm\ClientRelationshipProfileRecalculator;
use App\Services\Crm\ClientSegmentQueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `POST clients/search` — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`. POST (não GET): definição de
 * segmento não cabe em query string com segurança/tamanho, mesma regra de
 * `docs/gap-simplesvet/06-07-contrato-api.md`.
 */
class ClientSearchController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ClientSegmentQueryBuilder $queryBuilder,
        private readonly ClientRelationshipProfileRecalculator $recalculator,
        private readonly CommercialScopeResolver $scope,
    ) {}

    public function search(Request $request): AnonymousResourceCollection
    {
        $this->recalculator->ensureFreshFor($request->user());

        $profiles = $this->queryBuilder
            ->build($request->user(), $this->definitionFrom($request))
            ->orderByDesc('last_interaction_at')
            ->paginate(self::PER_PAGE);

        return ClientRelationshipProfileResource::collection($profiles);
    }

    /**
     * `GET clients/{id}/relationship-profile` — ficha do cliente (ciclo de vida, ABC, origem,
     * tags), achado de revisão do front de 18/19: não havia leitura de UM cliente só, apenas a
     * lista paginada de `search()`. Inclui arquivado (a ficha do cliente não deve sumir o
     * histórico de segmentação só porque ele saiu da carteira ativa).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->recalculator->ensureFreshFor($request->user());

        $profile = $this->scope->scopeQuery(ClientRelationshipProfile::query(), $request->user())
            ->where('client_id', $id)
            ->with(['client.tags', 'clientOrigin'])
            ->firstOrFail();

        return response()->json(['data' => new ClientRelationshipProfileResource($profile)]);
    }

    /** @return array<string, mixed> */
    private function definitionFrom(Request $request): array
    {
        $definition = (array) $request->input('segment', []);

        if ($request->boolean('include_archived')) {
            $definition['include_archived'] = true;
        }

        return $definition;
    }
}
