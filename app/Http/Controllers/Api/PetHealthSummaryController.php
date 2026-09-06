<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pet\PetHealthSummaryRequest;
use App\Http\Resources\PetHealthSummaryResource;
use App\Services\Medical\PetHealthSummaryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /pets/health-summary
 *
 * One response with the health roll-up of every pet of the authenticated tutor,
 * replacing the 2×N per-pet requests the dashboard used to fire.
 */
class PetHealthSummaryController extends Controller
{
    public function __construct(
        private readonly PetHealthSummaryService $petHealthSummaryService
    ) {}

    public function __invoke(PetHealthSummaryRequest $request): AnonymousResourceCollection
    {
        $windowDays = $request->windowDays();
        $summaries = $this->petHealthSummaryService->forTutor($request->user(), $windowDays);

        return PetHealthSummaryResource::collection($summaries)->additional([
            'meta' => [
                'pets_count' => $summaries->count(),
                'overdue_count' => $summaries->overdueCount(),
                'due_soon_count' => $summaries->dueSoonCount(),
                'window_days' => $windowDays,
            ],
        ]);
    }
}
