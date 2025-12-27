<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicalRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MedicalRecordController extends Controller
{
    public function index(Request $request)
    {
        $query = MedicalRecord::with(['pet', 'professional', 'appointment'])
            ->where('professional_id', $request->user()->id);

        $records = $query->orderBy('record_date', 'desc')->get();
        return response()->json($records);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pet_id' => 'required|exists:pets,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'record_date' => 'required|date',
            'weight' => 'nullable|numeric',
            'temperature' => 'nullable|numeric',
            'heart_rate' => 'nullable|integer',
            'respiratory_rate' => 'nullable|integer',
            'subjective' => 'nullable|string',
            'objective' => 'nullable|string',
            'assessment' => 'nullable|string',
            'plan' => 'nullable|string',
            'symptoms' => 'nullable|array',
            'diagnosis' => 'nullable|string',
            'treatment_plan' => 'nullable|string',
            'prescriptions' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['professional_id'] = $request->user()->id;

        $record = MedicalRecord::create($data);

        return response()->json([
            'message' => 'Prontuário criado com sucesso!',
            'record' => $record
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $record = MedicalRecord::with(['pet', 'professional', 'appointment'])
            ->where('professional_id', $request->user()->id)
            ->findOrFail($id);
        return response()->json($record);
    }

    public function update(Request $request, $id)
    {
        $record = MedicalRecord::where('professional_id', $request->user()->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'weight' => 'nullable|numeric',
            'temperature' => 'nullable|numeric',
            'heart_rate' => 'nullable|integer',
            'respiratory_rate' => 'nullable|integer',
            'subjective' => 'nullable|string',
            'objective' => 'nullable|string',
            'assessment' => 'nullable|string',
            'plan' => 'nullable|string',
            'symptoms' => 'nullable|array',
            'diagnosis' => 'nullable|string',
            'treatment_plan' => 'nullable|string',
            'prescriptions' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record->update($validator->validated());

        return response()->json([
            'message' => 'Prontuário atualizado com sucesso!',
            'record' => $record
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $record = MedicalRecord::where('professional_id', $request->user()->id)->findOrFail($id);
        $record->delete();

        return response()->json(['message' => 'Prontuário removido com sucesso!']);
    }
}
