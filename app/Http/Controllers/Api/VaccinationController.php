<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vaccination\StoreVaccinationRequest;
use App\Http\Requests\Vaccination\UpdateVaccinationRequest;
use App\Http\Resources\VaccinationResource;
use App\Models\Pet;
use App\Models\Vaccination;
use Illuminate\Http\Request;

/**
 * Legada: o app usa `PetHealthRecordsController` (`/pets/{pet}/health/vaccinations`), não estas
 * rotas (`/professional/vaccinations`). Mantida por compatibilidade, mas com a MESMA regra de
 * autoria de `PetHealthRecordsController::resolveProfessionalId()` — um tutor chamando `store()`
 * aqui não pode gravar `professional_id` como se um profissional tivesse participado do ato (ver
 * docs/vinculo-estoque-aplicacao-clinica.md item 0).
 */
class VaccinationController extends Controller
{
    use AuthorizesPetAccess;

    public function index(Request $request)
    {
        // `pet.user`/`pet.user.media` (not just `pet`) so VaccinationResource can
        // hoist the tutor's name + avatar without an N+1 per row — see
        // VaccinationsPage.vue (`vacc.tutor?.name`).
        $query = Vaccination::with(['pet.user.media', 'professional'])
            ->where('professional_id', $request->user()->id);

        if ($request->has('pet_id')) {
            // When filtering by a specific pet, ensure the requester has read access to it
            // (either owner or active grant). Without this a vet could probe records for
            // pets they don't actually have access to via another professional's data.
            $this->resolvePetForRead($request, (int) $request->pet_id);
            $query->where('pet_id', $request->pet_id);
        }

        $vaccinations = $query->orderBy('application_date', 'desc')->get();

        return VaccinationResource::collection($vaccinations);
    }

    public function store(StoreVaccinationRequest $request)
    {
        $data = $request->validated();

        // Vet must have write access to the pet they're vaccinating.
        $pet = $this->resolvePetForWrite($request, (int) $data['pet_id']);

        $data['professional_id'] = $this->resolveProfessionalId($request, $pet);
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

    public function update(UpdateVaccinationRequest $request, $id)
    {
        $vaccination = Vaccination::where('professional_id', $request->user()->id)->findOrFail($id);
        $vaccination->update($request->validated());

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
            $this->resolvePetForRead($request, (int) $request->pet_id);
            $query->where('pet_id', $request->pet_id);
        }
        $upcoming = $query->get();

        return response()->json($upcoming);
    }

    /**
     * Mesma regra de `PetHealthRecordsController::resolveProfessionalId()`: `null` quando quem
     * está chamando é o próprio tutor do pet, para não fabricar autoria clínica de alguém que
     * não participou do ato (migration `2026_09_06_000204_make_professional_id_nullable_on_
     * vaccinations_and_surgeries`).
     */
    private function resolveProfessionalId(Request $request, Pet $pet): ?int
    {
        $user = $request->user();
        if ($user === null || $this->isPetOwner($user, $pet)) {
            return null;
        }

        return $user->id;
    }
}
