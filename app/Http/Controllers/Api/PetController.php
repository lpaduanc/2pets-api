<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pet\StorePetRequest;
use App\Http\Requests\Pet\UpdatePetRequest;
use App\Http\Resources\PetResource;
use App\Models\Pet;
use Illuminate\Http\Request;

class PetController extends Controller
{
    /**
     * Display a listing of the user's pets.
     */
    public function index(Request $request)
    {
        $pets = $request->user()->pets()
            ->with(['dewormings', 'medications', 'weightHistory', 'vetAccesses'])
            ->get();

        return PetResource::collection($pets);
    }

    /**
     * Store a newly created pet.
     */
    public function store(StorePetRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;

        $pet = Pet::create($data);

        return (new PetResource($pet))
            ->additional(['message' => 'Pet criado com sucesso!'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified pet.
     */
    public function show(Request $request, $id)
    {
        $pet = $request->user()->pets()
            ->with(['dewormings', 'medications', 'weightHistory', 'vetAccesses'])
            ->findOrFail($id);

        return new PetResource($pet);
    }

    /**
     * Update the specified pet.
     */
    public function update(UpdatePetRequest $request, $id)
    {
        $pet = $request->user()->pets()->findOrFail($id);

        $data = $request->validated();

        $pet->update($data);

        return (new PetResource($pet))
            ->additional(['message' => 'Pet atualizado com sucesso!']);
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
