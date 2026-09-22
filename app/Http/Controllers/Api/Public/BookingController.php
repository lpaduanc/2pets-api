<?php

namespace App\Http\Controllers\Api\Public;

use App\DataTransferObjects\BookingRequestDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\BookAppointmentRequest;
use App\Http\Requests\Booking\ShowAvailabilityDaysRequest;
use App\Http\Requests\Booking\ShowAvailabilityRequest;
use App\Services\Booking\AvailabilityAggregationService;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\AvailableDaysCalculator;
use App\Services\Booking\BookingService;
use App\Services\Booking\WaitlistService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class BookingController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly AvailabilityAggregationService $availabilityAggregationService,
        private readonly AvailableDaysCalculator $availableDaysCalculator,
        private readonly BookingService $bookingService,
        private readonly WaitlistService $waitlistService
    ) {}

    /**
     * Fase 2, item 4: `professional_id` sozinho continua funcionando exatamente como antes
     * (compatibilidade com o app já publicado). Com `organization_id` e SEM
     * `professional_id`, o modo é agregado — união dos slots livres de toda a equipe
     * bookável, cada um já anotado com qual profissional atenderia.
     */
    public function availability(ShowAvailabilityRequest $request): JsonResponse
    {
        $date = Carbon::parse($request->input('date'));

        $slots = $request->isAggregated()
            ? $this->availabilityAggregationService->getAggregatedSlots(
                $request->organizationId(),
                $date,
                $request->serviceId(),
                $request->locationId(),
            )
            : $this->availabilityService->getAvailableSlots(
                $request->professionalId(),
                $date,
                $request->serviceId(),
                $request->context(),
            );

        return response()->json([
            'data' => $this->serializeSlots($slots),
        ]);
    }

    /** GET public/booking/availability-days — Fase 2, item 5. */
    public function availabilityDays(ShowAvailabilityDaysRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->availableDaysCalculator->daysWithAvailability($request->toQuery()),
        ]);
    }

    private function serializeSlots(Collection $slots): array
    {
        return $slots->map(fn ($slot) => $slot->toArray())->values()->all();
    }

    public function book(BookAppointmentRequest $request): JsonResponse
    {
        try {
            $dto = BookingRequestDTO::fromRequest([
                ...$request->validated(),
                'client_id' => auth()->id(),
            ]);

            $appointment = $this->bookingService->createBooking($dto);

            return response()->json([
                'message' => 'Booking created successfully',
                'data' => $appointment,
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $appointment = $this->bookingService->cancelBooking($id, $validated['reason']);

            return response()->json([
                'message' => 'Booking cancelled successfully',
                'data' => $appointment,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function reschedule(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'appointment_date' => 'required|date|after_or_equal:today',
        ]);

        try {
            $appointment = $this->bookingService->rescheduleBooking(
                $id,
                Carbon::parse($validated['appointment_date'])
            );

            return response()->json([
                'message' => 'Booking rescheduled successfully',
                'data' => $appointment,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function joinWaitlist(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'professional_id' => 'required|exists:users,id',
            'service_id' => 'nullable|exists:services,id',
            'pet_id' => 'nullable|exists:pets,id',
            'preferred_date' => 'required|date|after_or_equal:today',
            'preferred_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:500',
        ]);

        $waitlist = $this->waitlistService->addToWaitlist(
            $validated['professional_id'],
            auth()->id(),
            $validated['service_id'] ?? null,
            $validated['pet_id'] ?? null,
            Carbon::parse($validated['preferred_date']),
            $validated['preferred_time'] ?? null,
            $validated['notes'] ?? null
        );

        return response()->json([
            'message' => 'Added to waitlist successfully',
            'data' => $waitlist,
        ], 201);
    }
}
