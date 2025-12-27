<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PrescriptionController extends Controller
{
    public function index(Request $request)
    {
        $query = Prescription::with(['pet', 'professional'])
            ->where('professional_id', $request->user()->id);
        if ($request->has('pet_id')) {
            $query->where('pet_id', $request->pet_id);
        }
        $prescriptions = $query->orderBy('prescription_date', 'desc')->get();
        return response()->json($prescriptions);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pet_id' => 'required|exists:pets,id',
            'prescription_date' => 'required|date',
            'medications' => 'required|array',
            'instructions' => 'nullable|string',
            'valid_until' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $data['professional_id'] = $request->user()->id;
        $data['medications'] = json_encode($data['medications']);
        $prescription = Prescription::create($data);
        return response()->json(['message' => 'Prescrição criada com sucesso!', 'prescription' => $prescription], 201);
    }

    public function show(Request $request, $id)
    {
        $prescription = Prescription::with(['pet', 'professional'])
            ->where('professional_id', $request->user()->id)
            ->findOrFail($id);
        return response()->json($prescription);
    }

    public function update(Request $request, $id)
    {
        $prescription = Prescription::where('professional_id', $request->user()->id)->findOrFail($id);
        $validator = Validator::make($request->all(), [
            'prescription_date' => 'sometimes|date',
            'medications' => 'sometimes|array',
            'instructions' => 'nullable|string',
            'valid_until' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        if (isset($data['medications'])) {
            $data['medications'] = json_encode($data['medications']);
        }
        $prescription->update($data);
        return response()->json(['message' => 'Prescrição atualizada com sucesso!', 'prescription' => $prescription]);
    }

    public function destroy(Request $request, $id)
    {
        $prescription = Prescription::where('professional_id', $request->user()->id)->findOrFail($id);
        $prescription->delete();
        return response()->json(['message' => 'Prescrição removida com sucesso!']);
    }

    // Example endpoint: list valid prescriptions
    public function valid(Request $request)
    {
        $query = Prescription::where('professional_id', $request->user()->id)
            ->where(function ($q) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            });
        if ($request->has('pet_id')) {
            $query->where('pet_id', $request->pet_id);
        }
        $valid = $query->orderBy('valid_until', 'asc')->get();
        return response()->json($valid);
    }
}
