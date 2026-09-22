<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\Booking\AppointmentCheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST professional/appointments/{id}/check-in — item 21 do backlog gap-simplesvet.
 * `where('professional_id', ...)` garante que o agendamento é deste profissional, mesmo
 * padrão de `ConsultationController::start()`.
 */
class AppointmentCheckInController extends Controller
{
    public function __construct(private readonly AppointmentCheckInService $checkInService) {}

    public function __invoke(Request $request, int $id): JsonResponse
    {
        $appointment = Appointment::where('professional_id', $request->user()->id)->findOrFail($id);
        $appointment = $this->checkInService->checkIn($appointment);

        return response()->json([
            'message' => 'Check-in realizado.',
            'data' => new AppointmentResource($appointment),
        ]);
    }
}
