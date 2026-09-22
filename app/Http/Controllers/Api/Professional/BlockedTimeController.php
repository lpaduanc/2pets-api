<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Controller;
use App\Http\Requests\BlockedTime\ListBlockedTimeRequest;
use App\Http\Requests\BlockedTime\StoreBlockedTimeRequest;
use App\Http\Requests\BlockedTime\UpdateBlockedTimeRequest;
use App\Http\Resources\BlockedTimeResource;
use App\Models\BlockedTime;
use App\Services\Booking\BlockedTimeManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Bloqueios de agenda do profissional (`blocked_times`) — férias, almoço, compromisso.
 * `AvailabilityService` já lê esta tabela para excluir horários do slot público; até esta
 * fase não havia nenhum endpoint que a escrevesse.
 */
class BlockedTimeController extends Controller
{
    public function __construct(private readonly BlockedTimeManagementService $blockedTimeService) {}

    /** GET professional/blocked-times */
    public function index(ListBlockedTimeRequest $request): AnonymousResourceCollection
    {
        $blockedTimes = BlockedTime::query()
            ->where('professional_id', $request->targetProfessional()->id)
            ->when($request->filled('location_id'), fn ($query) => $query->where('location_id', $request->locationId()))
            ->orderBy('start_datetime')
            ->get();

        return BlockedTimeResource::collection($blockedTimes);
    }

    /** POST professional/blocked-times */
    public function store(StoreBlockedTimeRequest $request): JsonResponse
    {
        $blockedTime = $this->blockedTimeService->create(
            $request->targetProfessional(),
            $request->safe()->except(['professional_id']),
        );

        return (new BlockedTimeResource($blockedTime))
            ->additional(['message' => 'Bloqueio de agenda cadastrado.'])
            ->response()
            ->setStatusCode(201);
    }

    /** PUT professional/blocked-times/{id} */
    public function update(UpdateBlockedTimeRequest $request, int $id): BlockedTimeResource
    {
        $blockedTime = $this->blockedTimeService->update($request->blockedTime(), $request->validated());

        return new BlockedTimeResource($blockedTime);
    }

    /** DELETE professional/blocked-times/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $blockedTime = BlockedTime::findOrFail($id);

        Gate::forUser($request->user())->authorize('manage', $blockedTime);

        $this->blockedTimeService->delete($blockedTime, $request->user());

        return response()->json(['message' => 'Bloqueio de agenda removido.']);
    }

    /**
     * GET professional/blocked-times/history — bloqueios removidos (soft-deleted), com
     * quem removeu e quando (item 21 do backlog gap-simplesvet).
     */
    public function history(ListBlockedTimeRequest $request): AnonymousResourceCollection
    {
        $history = $this->blockedTimeService->history($request->targetProfessional(), $request->locationId());

        return BlockedTimeResource::collection($history);
    }
}
