<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\EnterFiscalContingencyRequest;
use App\Models\FiscalDocument;
use App\Models\Organization;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Fiscal\FiscalContingencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Contingência da NFC-e — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §4. Liga/desliga
 * com `fiscal-settings.manage`; transmitir com `fiscal-documents.issue` (rotas). Contingência é
 * estado da ORGANIZAÇÃO: vet volante sem organização recebe 404, como em `fiscal-settings`.
 */
class FiscalContingencyController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly FiscalContingencyService $contingency,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->contingency->status($this->organizationFor($request))]);
    }

    public function store(EnterFiscalContingencyRequest $request): JsonResponse
    {
        $status = $this->contingency->enter($this->organizationFor($request), $request->string('reason')->toString());

        return response()->json(['data' => $status, 'message' => 'Contingência ativada: NFC-e sai offline e NF-e aguarda a SEFAZ.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $result = $this->contingency->leave($this->organizationFor($request));

        return response()->json(['data' => $result['status'], 'transmission' => $result['transmission'], 'message' => 'Contingência encerrada.']);
    }

    public function transmit(Request $request): JsonResponse
    {
        $organization = $this->organizationFor($request);

        return response()->json(['data' => $this->contingency->transmit(FiscalOwner::forOrganization($organization->id)), 'message' => 'Transmissão concluída.']);
    }

    private function organizationFor(Request $request): Organization
    {
        Gate::forUser($request->user())->authorize('create', FiscalDocument::class);
        $organizationId = $this->scope->primaryOrganizationId($request->user());
        abort_if($organizationId === null, 404, 'Profissional sem organização não tem contingência fiscal.');

        return Organization::findOrFail($organizationId);
    }
}
