<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Controller;
use App\Http\Requests\Availability\ListAvailabilityRequest;
use App\Http\Requests\Availability\ReplaceWeeklyAvailabilityRequest;
use App\Http\Requests\Availability\StoreAvailabilityRequest;
use App\Http\Requests\Availability\UpdateAvailabilityRequest;
use App\Http\Resources\AvailabilityResource;
use App\Models\Availability;
use App\Services\Booking\AvailabilityManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Agenda semanal do profissional (`availabilities`) — fonte de verdade lida por
 * `GET /api/public/booking/availability` (`AvailabilityService`). Antes desta fase não
 * havia NENHUM endpoint de escrita: a tabela só era populada por seeder/admin.
 */
class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityManagementService $availabilityService) {}

    /** GET professional/availability */
    public function index(ListAvailabilityRequest $request): AnonymousResourceCollection
    {
        $availabilities = Availability::query()
            ->where('professional_id', $request->targetProfessional()->id)
            ->when($request->filled('location_id'), fn ($query) => $query->atLocation($request->locationId()))
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return AvailabilityResource::collection($availabilities);
    }

    /** POST professional/availability */
    public function store(StoreAvailabilityRequest $request): JsonResponse
    {
        $availability = $this->availabilityService->create(
            $request->targetProfessional(),
            $request->safe()->except(['professional_id']),
        );

        return (new AvailabilityResource($availability))
            ->additional(['message' => 'Disponibilidade cadastrada.'])
            ->response()
            ->setStatusCode(201);
    }

    /** PUT professional/availability/week */
    public function replaceWeek(ReplaceWeeklyAvailabilityRequest $request): AnonymousResourceCollection
    {
        $availabilities = $this->availabilityService->replaceWeek(
            $request->targetProfessional(),
            $request->locationId(),
            $request->windows(),
        );

        return AvailabilityResource::collection($availabilities);
    }

    /** PUT professional/availability/{id} */
    public function update(UpdateAvailabilityRequest $request, int $id): AvailabilityResource
    {
        $availability = $this->availabilityService->update($request->availability(), $request->validated());

        return new AvailabilityResource($availability);
    }

    /** DELETE professional/availability/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $availability = Availability::findOrFail($id);

        Gate::forUser($request->user())->authorize('manage', $availability);

        $this->availabilityService->delete($availability);

        return response()->json(['message' => 'Disponibilidade removida.']);
    }
}
