<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreClientSegmentRequest;
use App\Http\Resources\Crm\ClientSegmentResource;
use App\Models\ClientSegment;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `client-segments` — contrato `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`.
 * Segmento salvo é reutilizável em `POST message-campaigns/preview` (17) sem redigitar.
 */
class ClientSegmentController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $segments = $this->scope->scopeQuery(ClientSegment::query(), $request->user())
            ->orderByDesc('created_at')
            ->get();

        return ClientSegmentResource::collection($segments);
    }

    public function store(StoreClientSegmentRequest $request): JsonResponse
    {
        $segment = ClientSegment::create($request->validated() + $this->scope->ownershipFor($request->user()) + [
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => new ClientSegmentResource($segment)], 201);
    }

    public function show(Request $request, ClientSegment $clientSegment): ClientSegmentResource
    {
        abort_unless($this->scope->userCanAccess($clientSegment, $request->user()), 403);

        return new ClientSegmentResource($clientSegment);
    }

    public function update(StoreClientSegmentRequest $request, ClientSegment $clientSegment): JsonResponse
    {
        abort_unless($this->scope->userCanAccess($clientSegment, $request->user()), 403);

        $clientSegment->update($request->validated());

        return response()->json(['data' => new ClientSegmentResource($clientSegment)]);
    }

    public function destroy(Request $request, ClientSegment $clientSegment): JsonResponse
    {
        abort_unless($this->scope->userCanAccess($clientSegment, $request->user()), 403);

        $clientSegment->delete();

        return response()->json(['message' => 'Segmento removido com sucesso.']);
    }
}
