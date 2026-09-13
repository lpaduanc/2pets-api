<?php

namespace App\Http\Controllers;

use App\Http\Requests\Registration\Draft\SaveCompanyDraftRequest;
use App\Http\Requests\Registration\Draft\SaveProfessionalDraftRequest;
use App\Http\Requests\Registration\Draft\SaveTutorDraftRequest;
use App\Repositories\Registration\CompanyDraftRepository;
use App\Repositories\Registration\ProfessionalDraftRepository;
use App\Repositories\Registration\TutorDraftRepository;
use App\Services\Registration\RegistrationDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autosave progressivo do cadastro (tutor/profissional/empresa): grava em cache (restauração
 * rápida do formulário inteiro) E no banco (dado já estruturado), um passo de cada vez, antes
 * da conclusão de cadastro. Controller só traduz HTTP — toda a regra mora em
 * `RegistrationDraftService` + um `RegistrationDraftRepository` por tipo de conta.
 *
 * Colisão de documento (CPF/CNPJ/CRMV) é pega pelo `Rule::unique` de cada Form Request e,
 * numa corrida entre duas requisições simultâneas que passe da validação, pelo índice único
 * do banco — nos dois casos o handler global de `bootstrap/app.php` converte para o mesmo
 * contrato de duplicidade. Nenhum catch local é necessário aqui.
 */
class RegistrationDraftController extends Controller
{
    public function __construct(
        private readonly RegistrationDraftService $draftService,
        private readonly ProfessionalDraftRepository $professionalDraftRepository,
        private readonly CompanyDraftRepository $companyDraftRepository,
        private readonly TutorDraftRepository $tutorDraftRepository,
    ) {}

    public function saveProfessionalDraft(SaveProfessionalDraftRequest $request): JsonResponse
    {
        return response()->json($this->draftService->save(
            $this->professionalDraftRepository,
            $request->user(),
            $request->all(),
            $request->validated(),
        ));
    }

    public function loadProfessionalDraft(Request $request): JsonResponse
    {
        return response()->json(
            $this->draftService->load($this->professionalDraftRepository, $request->user())
        );
    }

    public function deleteProfessionalDraft(Request $request): JsonResponse
    {
        $this->draftService->delete($this->professionalDraftRepository, $request->user());

        return $this->draftDeletedResponse();
    }

    public function saveCompanyDraft(SaveCompanyDraftRequest $request): JsonResponse
    {
        return response()->json($this->draftService->save(
            $this->companyDraftRepository,
            $request->user(),
            $request->all(),
            $request->validated(),
        ));
    }

    public function loadCompanyDraft(Request $request): JsonResponse
    {
        return response()->json(
            $this->draftService->load($this->companyDraftRepository, $request->user())
        );
    }

    public function deleteCompanyDraft(Request $request): JsonResponse
    {
        $this->draftService->delete($this->companyDraftRepository, $request->user());

        return $this->draftDeletedResponse();
    }

    public function saveTutorDraft(SaveTutorDraftRequest $request): JsonResponse
    {
        return response()->json($this->draftService->save(
            $this->tutorDraftRepository,
            $request->user(),
            $request->all(),
            $request->validated(),
        ));
    }

    public function loadTutorDraft(Request $request): JsonResponse
    {
        return response()->json(
            $this->draftService->load($this->tutorDraftRepository, $request->user())
        );
    }

    public function deleteTutorDraft(Request $request): JsonResponse
    {
        $this->draftService->delete($this->tutorDraftRepository, $request->user());

        return $this->draftDeletedResponse();
    }

    private function draftDeletedResponse(): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Rascunho removido']);
    }
}
