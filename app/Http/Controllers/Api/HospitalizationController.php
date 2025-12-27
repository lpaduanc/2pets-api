<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hospitalization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HospitalizationController extends Controller
{
    public function index(Request $request)
    {
        $query = Hospitalization::with(['pet', 'professional'])
            ->where('professional_id', $request->user()->id);
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        $hospitalizations = $query->orderBy('admission_date', 'desc')->get();
        return response()->json($hospitalizations);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pet_id' => 'required|exists:pets,id',
            'admission_date' => 'required|date',
            'reason' => 'required|string|max:255',
            'status' => 'required|in:active,discharged,transferred',
            'daily_notes' => 'nullable|array',
            'medications' => 'nullable|array',
            'total_cost' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['professional_id'] = $request->user()->id;

        // JSON encoding handled by model casts or manually if needed, 
        // but since we cast 'array' in model, Laravel handles it automatically if passed as array.
        // However, to be safe with API input:
        if (isset($data['daily_notes']) && is_array($data['daily_notes'])) {
            // Model casts will handle array -> json on save
        }

        $hospitalization = Hospitalization::create($data);
        return response()->json(['message' => 'Hospitalization created', 'hospitalization' => $hospitalization], 201);
    }

    public function show(Request $request, $id)
    {
        $hospitalization = Hospitalization::with(['pet', 'professional'])
            ->where('professional_id', $request->user()->id)
            ->findOrFail($id);
        return response()->json($hospitalization);
    }

    public function update(Request $request, $id)
    {
        $hospitalization = Hospitalization::where('professional_id', $request->user()->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'discharge_date' => 'nullable|date',
            'reason' => 'sometimes|required|string|max:255',
            'status' => 'sometimes|required|in:active,discharged,transferred',
            'daily_notes' => 'nullable|array',
            'medications' => 'nullable|array',
            'total_cost' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $hospitalization->update($validator->validated());
        return response()->json(['message' => 'Hospitalization updated', 'hospitalization' => $hospitalization]);
    }

    public function destroy(Request $request, $id)
    {
        $hospitalization = Hospitalization::where('professional_id', $request->user()->id)->findOrFail($id);
        $hospitalization->delete();
        return response()->json(['message' => 'Hospitalization removed']);
    }
}
