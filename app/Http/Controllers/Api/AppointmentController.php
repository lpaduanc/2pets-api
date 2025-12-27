<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Appointment::with(['client', 'pet', 'professional'])
            ->forProfessional($request->user()->id);

        // Filters
        if ($request->has('date')) {
            $query->whereDate('appointment_date', $request->date);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $appointments = $query->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->get();

        return response()->json($appointments);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:users,id',
            'pet_id' => 'required|exists:pets,id',
            'appointment_date' => 'required|date',
            'appointment_time' => 'required',
            'duration' => 'nullable|integer|min:15',
            'type' => 'required|in:consultation,surgery,vaccination,exam,emergency,grooming,checkup',
            'reason' => 'nullable|string',
            'notes' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['professional_id'] = $request->user()->id;
        $data['status'] = 'scheduled';

        $appointment = Appointment::create($data);

        return response()->json([
            'message' => 'Consulta agendada com sucesso!',
            'appointment' => $appointment->load(['client', 'pet'])
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $appointment = Appointment::with(['client', 'pet', 'professional', 'medicalRecords', 'prescriptions', 'vaccinations'])
            ->where('professional_id', $request->user()->id)
            ->findOrFail($id);

        return response()->json($appointment);
    }

    public function update(Request $request, $id)
    {
        $appointment = Appointment::where('professional_id', $request->user()->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'appointment_date' => 'sometimes|date',
            'appointment_time' => 'sometimes',
            'duration' => 'nullable|integer|min:15',
            'type' => 'sometimes|in:consultation,surgery,vaccination,exam,emergency,grooming,checkup',
            'status' => 'sometimes|in:scheduled,confirmed,in_progress,completed,cancelled,no_show',
            'reason' => 'nullable|string',
            'notes' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $appointment->update($validator->validated());

        return response()->json([
            'message' => 'Consulta atualizada com sucesso!',
            'appointment' => $appointment->load(['client', 'pet'])
        ]);
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

        return response()->json($appointments);
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

        return response()->json($appointments);
    }
}
