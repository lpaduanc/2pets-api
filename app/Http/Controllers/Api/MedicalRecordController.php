<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\MedicalRecord\StoreMedicalRecordRequest;
use App\Http\Requests\MedicalRecord\UpdateMedicalRecordRequest;
use App\Models\MedicalRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

class MedicalRecordController extends Controller
{
    use AuthorizesPetAccess, PaginatesResults;

    /**
     * The records screen sends its own `per_page`; this default only has to keep
     * the callers that read the whole list to populate a select working.
     */
    private const DEFAULT_PER_PAGE = 100;

    public function index(Request $request)
    {
        // Vet sees only records they authored. Tutor's view is served by a different endpoint.
        $records = MedicalRecord::with(['pet', 'professional', 'appointment'])
            ->where('professional_id', $request->user()->id)
            ->orderBy('record_date', 'desc')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        return JsonResource::collection($records);
    }

    public function store(StoreMedicalRecordRequest $request)
    {
        $data = $request->validated();

        // Defensive check: a vet (or anyone) must have write access to the pet they
        // are creating a record for. Without this gate the pet_id in the payload is
        // completely unchecked — someone could attach a record to any pet.
        $this->resolvePetForWrite($request, (int) $data['pet_id']);

        $data['professional_id'] = $request->user()->id;
        $record = MedicalRecord::create($data);

        return response()->json([
            'message' => 'Prontuário criado com sucesso!',
            'record' => $record,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $record = MedicalRecord::with(['pet', 'professional', 'appointment'])->findOrFail($id);

        // MedicalRecordPolicy handles the triangle owner/author/granted-vet.
        Gate::forUser($request->user())->authorize('view', $record);

        return response()->json($record);
    }

    public function update(UpdateMedicalRecordRequest $request, $id)
    {
        $record = MedicalRecord::findOrFail($id);

        Gate::forUser($request->user())->authorize('update', $record);

        $record->update($request->validated());

        return response()->json([
            'message' => 'Prontuário atualizado com sucesso!',
            'record' => $record,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $record = MedicalRecord::findOrFail($id);

        Gate::forUser($request->user())->authorize('delete', $record);

        $record->delete();

        return response()->json(['message' => 'Prontuário removido com sucesso!']);
    }
}
