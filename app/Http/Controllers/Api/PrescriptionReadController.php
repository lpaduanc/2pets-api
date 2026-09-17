<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Resources\PrescriptionResource;
use App\Models\Pet;
use App\Models\Prescription;
use App\Policies\PrescriptionPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Leitura compartilhada tutor + veterinário autorizado — contrato
 * docs/atendimento-veterinario/03-contrato-receituario.md §1/§6. Mesmo espírito de
 * `MedicalRecordReadController::forPet`: fora do prefixo `professional/`, porque quem lê
 * aqui pode ser o dono do pet, não só o profissional.
 */
class PrescriptionReadController extends Controller
{
    use PaginatesResults;

    private const DEFAULT_PER_PAGE = 20;

    public function __construct(private readonly PrescriptionPolicy $prescriptionPolicy) {}

    /**
     * GET pets/{pet}/prescriptions — só EMITIDAS, incluindo as CANCELADAS (contrato §6: uma
     * prescrição cancelada continua visível no histórico, nunca some), ordem cronológica
     * desc por `issued_at`. Vet cuja autorização foi revogada depois de emitir continua
     * vendo só o que ele mesmo prescreveu; tutor e vet com acesso ativo veem o histórico
     * inteiro do pet.
     */
    public function forPet(Request $request, int $pet): AnonymousResourceCollection
    {
        $petModel = Pet::findOrFail($pet);
        $user = $request->user();

        Gate::forUser($user)->authorize('viewAny', [Prescription::class, $petModel]);

        $query = Prescription::with(Prescription::RESOURCE_RELATIONS)
            ->where('pet_id', $petModel->id)
            ->whereNotNull('issued_at');

        if ($this->prescriptionPolicy->restrictedToOwnPrescriptions($user, $petModel)) {
            $query->where('professional_id', $user->id);
        }

        $prescriptions = $query->orderBy('issued_at', 'desc')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        return PrescriptionResource::collection($prescriptions);
    }
}
