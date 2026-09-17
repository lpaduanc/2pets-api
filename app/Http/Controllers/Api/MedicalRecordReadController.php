<?php

namespace App\Http\Controllers\Api;

use App\Enums\MedicalRecordStatus;
use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\MedicalRecordResource;
use App\Models\MedicalRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Leitura compartilhada tutor + veterinário autorizado — contrato
 * docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md §1 "Leitura".
 *
 * Fora do prefixo `professional` de propósito: quem lê aqui pode ser o dono do pet, não só
 * o profissional. Separado de `MedicalRecordController` (que mantém o shape de resposta
 * legado usado pela tela do profissional) para não misturar os dois contratos de resposta.
 */
class MedicalRecordReadController extends Controller
{
    use AuthorizesPetAccess, PaginatesResults;

    private const DEFAULT_PER_PAGE = 20;

    /** GET pets/{pet}/medical-records — só finalizados, ordem cronológica desc (contrato §1). */
    public function forPet(Request $request, int $pet): AnonymousResourceCollection
    {
        $petModel = $this->resolvePetForRead($request, $pet);

        $records = MedicalRecord::with(MedicalRecord::RESOURCE_RELATIONS)
            ->where('pet_id', $petModel->id)
            ->where('status', MedicalRecordStatus::FINALIZED->value)
            ->orderBy('record_date', 'desc')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        return MedicalRecordResource::collection($records);
    }

    /**
     * GET medical-records/{id} — `MedicalRecordPolicy::view` decide; rascunho só para o autor.
     *
     * Invariante de privacidade §B (docs/atendimento-veterinario/
     * 08-consulta-autorizada-por-agendamento.md): o autor pode chegar aqui sem `PetVetAccess`
     * (agendamento apenas) — nesse caso o `pet` embutido vem minimizado.
     */
    public function show(Request $request, int $id): MedicalRecordResource
    {
        $record = MedicalRecord::with(MedicalRecord::RESOURCE_RELATIONS)->findOrFail($id);

        Gate::forUser($request->user())->authorize('view', $record);

        $record->setRelation('pet', $this->minimizePetUnlessFullAccess($request->user(), $record->pet));

        return new MedicalRecordResource($record);
    }
}
