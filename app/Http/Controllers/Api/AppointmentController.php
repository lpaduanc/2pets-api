<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Appointment\StoreAppointmentRequest;
use App\Http\Requests\Appointment\UpdateAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    use PaginatesResults;

    /**
     * The agenda screen renders the full list and also feeds the invoice and
     * medical record form selects from it, so the default page covers a
     * professional's whole agenda.
     */
    private const DEFAULT_PER_PAGE = 200;

    public function index(Request $request)
    {
        $query = Appointment::with(['client', 'pet', 'professional'])
            ->forProfessional($request->user()->id);

        // Filters
        if ($request->has('date')) {
            // Range instead of whereDate(): appointment_date is a datetime column,
            // so whereDate() would cast it and block index usage.
            $filterDate = Carbon::parse($request->date)->startOfDay();
            $query->where('appointment_date', '>=', $filterDate)
                ->where('appointment_date', '<', $filterDate->copy()->addDay());
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $appointments = $query->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        return AppointmentResource::collection($appointments);
    }

    public function store(StoreAppointmentRequest $request)
    {
        $data = $request->validated();
        $data['professional_id'] = $request->user()->id;
        $data['status'] = 'scheduled';

        $appointment = Appointment::create($data);

        return (new AppointmentResource($appointment->load(['client', 'pet'])))
            ->additional(['message' => 'Consulta agendada com sucesso!'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, $id)
    {
        $appointment = Appointment::with(['client', 'pet', 'professional', 'medicalRecords', 'prescriptions', 'vaccinations'])
            ->where('professional_id', $request->user()->id)
            ->findOrFail($id);

        return new AppointmentResource($appointment);
    }

    public function update(UpdateAppointmentRequest $request, $id)
    {
        $appointment = Appointment::where('professional_id', $request->user()->id)->findOrFail($id);

        $appointment->update($request->validated());

        return (new AppointmentResource($appointment->load(['client', 'pet'])))
            ->additional(['message' => 'Consulta atualizada com sucesso!']);
    }

    public function destroy(Request $request, $id)
    {
        $appointment = Appointment::where('professional_id', $request->user()->id)->findOrFail($id);
        $appointment->delete();

        return response()->json(['message' => 'Consulta removida com sucesso!']);
    }

    public function today(Request $request)
    {
        $appointments = Appointment::with(['client', 'pet'])
            ->forProfessional($request->user()->id)
            ->today()
            ->orderBy('appointment_time')
            ->get();

        return AppointmentResource::collection($appointments);
    }

    public function upcoming(Request $request)
    {
        $appointments = Appointment::with(['client', 'pet'])
            ->forProfessional($request->user()->id)
            ->upcoming()
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->limit(10)
            ->get();

        return AppointmentResource::collection($appointments);
    }
}
