<?php

namespace App\Http\Controllers\Api\Public;

use App\DataTransferObjects\BookingRequestDTO;
use App\Http\Controllers\Controller;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingService;
use App\Services\Booking\WaitlistService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly BookingService $bookingService,
        private readonly WaitlistService $waitlistService
    ) {}

    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'professional_id' => 'required|exists:users,id',
            'date' => 'required|date|after_or_equal:today',
            'service_id' => 'nullable|exists:services,id',
        ]);

        $slots = $this->availabilityService->getAvailableSlots(
            $validated['professional_id'],
            Carbon::parse($validated['date']),
            $validated['service_id'] ?? null
        );

        return response()->json([
            'data' => $slots->map(fn($slot) => $slot->toArray())->values()
        ]);
    }

    public function book(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'professional_id' => 'required|exists:users,id',
            'service_id' => 'required|exists:services,id',
            'pet_id' => 'nullable|exists:pets,id',
            'appointment_date' => 'required|date|after_or_equal:today',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $dto = BookingRequestDTO::fromRequest([
                ...$validated,
                'client_id' => auth()->id(),
            ]);

            $appointment = $this->bookingService->createBooking($dto);

            return response()->json([
                'message' => 'Booking created successfully',
                'data' => $appointment,
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage()
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
                'message' => $e->getMessage()
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
                'message' => $e->getMessage()
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

