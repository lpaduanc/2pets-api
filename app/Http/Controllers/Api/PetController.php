<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PetController extends Controller
{
    /**
     * Display a listing of the user's pets.
     */
    public function index(Request $request)
    {
        $pets = $request->user()->pets()->get()->map(function ($pet) {
            return [
                'id' => $pet->id,
                'name' => $pet->name,
                'species' => $pet->species,
                'breed' => $pet->breed,
                'age' => $pet->birth_date ? now()->diffInYears($pet->birth_date) : 0,
                'birthDate' => $pet->birth_date ? $pet->birth_date->format('d/m/Y') : null,
                'gender' => $pet->gender,
                'weight' => $pet->weight,
                'color' => $pet->color,
                'image' => $pet->image_url,
                'neutered' => $pet->neutered,
                'bloodType' => $pet->blood_type,
                'allergies' => $pet->allergies,
                'chronicDiseases' => $pet->chronic_diseases,
                'currentMedications' => $pet->current_medications,
                'temperament' => is_string($pet->temperament) ? json_decode($pet->temperament) : ($pet->temperament ?? []),
                'behaviorNotes' => $pet->behavior_notes,
                'socialWith' => is_string($pet->social_with) ? json_decode($pet->social_with) : ($pet->social_with ?? []),
                'notes' => $pet->notes,
                'vaccinesUpToDate' => true, // TODO: Calculate based on vaccine records
                'hasAllergies' => !empty($pet->allergies)
            ];
        });

        return response()->json($pets);
    }

    /**
     * Store a newly created pet.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'species' => 'required|in:dog,cat',
            'breed' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date',
            'gender' => 'required|in:male,female',
            'weight' => 'nullable|numeric',
            'color' => 'nullable|string|max:255',
            'neutered' => 'boolean',
            'blood_type' => 'nullable|string|max:50',
            'allergies' => 'nullable|string',
            'chronic_diseases' => 'nullable|string',
            'current_medications' => 'nullable|string',
            'temperament' => 'nullable|array',
            'behavior_notes' => 'nullable|string',
            'social_with' => 'nullable|array',
            'notes' => 'nullable|string',
            'image_url' => 'nullable|url'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['user_id'] = $request->user()->id;

        // Convert arrays to JSON
        if (isset($data['temperament'])) {
            $data['temperament'] = json_encode($data['temperament']);
        }
        if (isset($data['social_with'])) {
            $data['social_with'] = json_encode($data['social_with']);
        }

        $pet = Pet::create($data);

        return response()->json([
            'message' => 'Pet criado com sucesso!',
            'pet' => $pet
        ], 201);
    }

    /**
     * Display the specified pet.
     */
    public function show(Request $request, $id)
    {
        $pet = $request->user()->pets()->findOrFail($id);

        return response()->json($pet);
    }

    /**
     * Update the specified pet.
     */
    public function update(Request $request, $id)
    {
        $pet = $request->user()->pets()->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'species' => 'sometimes|required|in:dog,cat',
            'breed' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date',
            'gender' => 'sometimes|required|in:male,female',
            'weight' => 'nullable|numeric',
            'color' => 'nullable|string|max:255',
            'neutered' => 'boolean',
            'blood_type' => 'nullable|string|max:50',
            'allergies' => 'nullable|string',
            'chronic_diseases' => 'nullable|string',
            'current_medications' => 'nullable|string',
            'temperament' => 'nullable|array',
            'behavior_notes' => 'nullable|string',
            'social_with' => 'nullable|array',
            'notes' => 'nullable|string',
            'image_url' => 'nullable|url'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Convert arrays to JSON
        if (isset($data['temperament'])) {
            $data['temperament'] = json_encode($data['temperament']);
        }
        if (isset($data['social_with'])) {
            $data['social_with'] = json_encode($data['social_with']);
        }

        $pet->update($data);

        return response()->json([
            'message' => 'Pet atualizado com sucesso!',
            'pet' => $pet
        ]);
    }

    /**
     * Remove the specified pet.
     */
    public function destroy(Request $request, $id)
    {
        $pet = $request->user()->pets()->findOrFail($id);
        $pet->delete();

        return response()->json([
            'message' => 'Pet removido com sucesso!'
        ]);
    }
}
