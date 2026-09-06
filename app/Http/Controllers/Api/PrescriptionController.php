<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Validator;

class PrescriptionController extends Controller
{
    use AuthorizesPetAccess, PaginatesResults;

    /** Covers the prescription history screen and the selects fed from it. */
    private const DEFAULT_PER_PAGE = 100;

    public function index(Request $request)
    {
        $query = Prescription::with(['pet', 'professional'])
            ->where('professional_id', $request->user()->id);

        if ($request->has('pet_id')) {
            $this->resolvePetForRead($request, (int) $request->pet_id);
            $query->where('pet_id', $request->pet_id);
        }

        $prescriptions = $query->orderBy('prescription_date', 'desc')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        return JsonResource::collection($prescriptions);
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

        // Vet must have write access to the pet they're prescribing for.
        $this->resolvePetForWrite($request, (int) $data['pet_id']);

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
            $this->resolvePetForRead($request, (int) $request->pet_id);
            $query->where('pet_id', $request->pet_id);
        }
        $valid = $query->orderBy('valid_until', 'asc')->get();

        return response()->json($valid);
    }
}
