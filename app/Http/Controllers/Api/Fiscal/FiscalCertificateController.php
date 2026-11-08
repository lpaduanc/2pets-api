<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\StoreFiscalCertificateRequest;
use App\Http\Resources\Fiscal\FiscalCertificateResource;
use App\Models\FiscalDocument;
use App\Models\Organization;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Fiscal\Certificate\FiscalCertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Certificado A1 da organização — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md
 * §3. Rota com `fiscal-settings.manage` e, aqui, a régua "só OWNER" de todo o fiscal
 * (`FiscalDocumentPolicy::create`). Nenhuma resposta traz o arquivo, a senha ou a referência
 * interna — só os metadados (`FiscalCertificateResource`).
 */
class FiscalCertificateController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly FiscalCertificateService $certificates,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $certificate = $this->certificates->current($this->organizationFor($request));

        return response()->json(['data' => $certificate === null ? null : new FiscalCertificateResource($certificate)]);
    }

    public function store(StoreFiscalCertificateRequest $request): JsonResponse
    {
        $organization = $this->organizationFor($request);
        $certificate = $this->certificates->upload(
            $organization,
            (string) $request->file('certificate')->get(),
            (string) $request->input('password'),
            $request->user(),
        );

        return response()->json(['data' => new FiscalCertificateResource($certificate), 'message' => 'Certificado A1 cadastrado.'], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->certificates->remove($this->organizationFor($request), $request->user());

        return response()->json(['message' => 'Certificado A1 removido. A emissão volta a ser simulada.']);
    }

    private function organizationFor(Request $request): Organization
    {
        Gate::forUser($request->user())->authorize('create', FiscalDocument::class);
        $organizationId = $this->scope->primaryOrganizationId($request->user());
        abort_if($organizationId === null, 404, 'Certificado A1 é da empresa: profissional sem organização não transmite à SEFAZ.');

        return Organization::findOrFail($organizationId);
    }
}
