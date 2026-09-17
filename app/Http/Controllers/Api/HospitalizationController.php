<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hospitalization\StoreHospitalizationRequest;
use App\Http\Requests\Hospitalization\UpdateHospitalizationRequest;
use App\Http\Resources\HospitalizationResource;
use App\Models\Hospitalization;
use App\Services\Hospitalization\HospitalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md e
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §6.2.
 *
 * Controller fino: admissão, cobrança e fechamento da estadia vivem em
 * `HospitalizationService` (que reaproveita `ConsultationService`/`AppointmentInvoiceService`
 * sem alteração de lógica) — este controller só valida, delega e devolve o Resource.
 *
 * `index`/`show`/`update`/`destroy` deixaram de filtrar só por `professional_id` — bug
 * corrigido pelo doc 12: um colega `clinic_vet` da mesma organização de quem admitiu não
 * conseguia nem LER a internação. `HospitalizationService::visibleTo()` resolve a listagem;
 * `HospitalizationPolicy` resolve o registro único.
 */
class HospitalizationController extends Controller
{
    use PaginatesResults;

    /** Covers the hospitalization board of a professional. */
    private const DEFAULT_PER_PAGE = 100;

    /**
     * Mesma projeção usada em `AppointmentController`/`ConsultationController` para
     * `invoice_id`: só `id`/`appointment_id`, nunca a fatura inteira eager-loaded à toa.
     * `progressNotes`/`careLogs` reaproveitam `VetContactResource` — precisam de
     * `author.professional` (contrato doc 12 §7).
     *
     * @var list<string>
     */
    private const RESOURCE_RELATIONS = [
        'pet', 'professional', 'appointment.invoice:id,appointment_id',
        'indicatingMedicalRecord', 'progressNotes.author.professional', 'careLogs.author.professional',
    ];

    public function __construct(private readonly HospitalizationService $hospitalizationService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->hospitalizationService->visibleTo($request->user())->with(self::RESOURCE_RELATIONS);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $hospitalizations = $query->orderBy('admission_date', 'desc')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        return HospitalizationResource::collection($hospitalizations);
    }

    public function store(StoreHospitalizationRequest $request): JsonResponse
    {
        $hospitalization = $this->hospitalizationService->admit($request->validated(), $request->user());

        return (new HospitalizationResource($hospitalization->load(self::RESOURCE_RELATIONS)))
            ->additional(['message' => 'Internação registrada com sucesso!'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id): HospitalizationResource
    {
        $hospitalization = Hospitalization::with(self::RESOURCE_RELATIONS)->findOrFail($id);
        Gate::forUser($request->user())->authorize('view', $hospitalization);

        return new HospitalizationResource($hospitalization);
    }

    public function update(UpdateHospitalizationRequest $request, int $id): JsonResponse
    {
        $hospitalization = Hospitalization::with('professional')->findOrFail($id);
        Gate::forUser($request->user())->authorize('update', $hospitalization);

        $hospitalization = $this->hospitalizationService->update($hospitalization, $request->validated());

        return (new HospitalizationResource($hospitalization->load(self::RESOURCE_RELATIONS)))
            ->additional(['message' => 'Internação atualizada com sucesso!'])
            ->response();
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $hospitalization = Hospitalization::with('professional')->findOrFail($id);
        Gate::forUser($request->user())->authorize('delete', $hospitalization);

        $hospitalization->delete();

        return response()->json(['message' => 'Hospitalization removed']);
    }
}
