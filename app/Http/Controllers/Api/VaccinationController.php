<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vaccination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VaccinationController extends Controller
{
    public function index(Request $request)
    {
        $query = Vaccination::with(['pet', 'professional'])
            ->where('professional_id', $request->user()->id);
        if ($request->has('pet_id')) {
            $query->where('pet_id', $request->pet_id);
        }
        $vaccinations = $query->orderBy('application_date', 'desc')->get();
        return response()->json($vaccinations);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pet_id' => 'required|exists:pets,id',
            'vaccine_name' => 'required|string|max:255',
            'manufacturer' => 'nullable|string|max:255',
            'batch_number' => 'nullable|string|max:255',
            'application_date' => 'required|date',
            'next_dose_date' => 'nullable|date',
            'dose_number' => 'nullable|integer',
            'notes' => 'nullable|string',
            'adverse_reactions' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $data['professional_id'] = $request->user()->id;
        $vaccination = Vaccination::create($data);
        return response()->json(['message' => 'Vacinação registrada com sucesso!', 'vaccination' => $vaccination], 201);
    }

    public function show(Request $request, $id)
    {
        $vaccination = Vaccination::with(['pet', 'professional'])
            ->where('professional_id', $request->user()->id)
            ->findOrFail($id);
        return response()->json($vaccination);
    }

    public function update(Request $request, $id)
    {
        $vaccination = Vaccination::where('professional_id', $request->user()->id)->findOrFail($id);
        $validator = Validator::make($request->all(), [
            'vaccine_name' => 'sometimes|required|string|max:255',
            'manufacturer' => 'nullable|string|max:255',
            'batch_number' => 'nullable|string|max:255',
            'application_date' => 'sometimes|date',
            'next_dose_date' => 'nullable|date',
            'dose_number' => 'nullable|integer',
            'notes' => 'nullable|string',
            'adverse_reactions' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $vaccination->update($validator->validated());
        return response()->json(['message' => 'Vacinação atualizada com sucesso!', 'vaccination' => $vaccination]);
    }

    public function destroy(Request $request, $id)
    {
        $vaccination = Vaccination::where('professional_id', $request->user()->id)->findOrFail($id);
        $vaccination->delete();
        return response()->json(['message' => 'Vacinação removida com sucesso!']);
    }

    // Example: upcoming vaccinations for a pet
    public function upcoming(Request $request)
    {
        $query = Vaccination::where('professional_id', $request->user()->id)
            ->whereDate('next_dose_date', '>=', now())
            ->orderBy('next_dose_date');
        if ($request->has('pet_id')) {
            $query->where('pet_id', $request->pet_id);
        }
        $upcoming = $query->get();
        return response()->json($upcoming);
    }
}
