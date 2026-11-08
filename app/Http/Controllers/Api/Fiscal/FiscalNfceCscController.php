<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\Enums\FiscalEnvironment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\UpdateFiscalNfceCscRequest;
use App\Models\FiscalDocument;
use App\Models\Organization;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Fiscal\Certificate\FiscalNfceCscService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * CSC da NFC-e por ambiente — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md
 * §4. Mesma régua do certificado (`fiscal-settings.manage` + só OWNER). O token entra e nunca sai.
 */
class FiscalNfceCscController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly FiscalNfceCscService $cscs,
    ) {}

    public function update(UpdateFiscalNfceCscRequest $request): JsonResponse
    {
        $organization = $this->organizationFor($request);
        $this->cscs->save(
            $organization,
            FiscalEnvironment::from($request->string('environment')->toString()),
            $request->string('csc_id')->toString(),
            $request->string('csc_token')->toString(),
            $request->user(),
        );

        return response()->json(['data' => $this->cscs->summary($organization), 'message' => 'CSC da NFC-e salvo.']);
    }

    public function destroy(Request $request, string $environment): JsonResponse
    {
        $organization = $this->organizationFor($request);
        $this->cscs->remove($organization, FiscalEnvironment::from($environment), $request->user());

        return response()->json(['data' => $this->cscs->summary($organization), 'message' => 'CSC da NFC-e removido.']);
    }

    private function organizationFor(Request $request): Organization
    {
        Gate::forUser($request->user())->authorize('create', FiscalDocument::class);
        $organizationId = $this->scope->primaryOrganizationId($request->user());
        abort_if($organizationId === null, 404, 'CSC da NFC-e é da empresa: profissional sem organização não emite NFC-e própria.');

        return Organization::findOrFail($organizationId);
    }
}
