<?php

namespace App\Http\Controllers;

use App\Enums\ProfessionalType;
use App\Http\Requests\Registration\CompleteCompanyRegistrationRequest;
use App\Http\Requests\Registration\CompleteGenericProfessionalRegistrationRequest;
use App\Http\Requests\Registration\CompleteTutorRegistrationRequest;
use App\Http\Requests\Registration\CompleteVetRegistrationRequest;
use App\Http\Resources\OrganizationSummaryResource;
use App\Models\User;
use App\Services\Registration\RegistrationCompletionService;
use App\Services\Registration\TutorAccountClaimService;
use Illuminate\Http\Request;

class RegistrationCompletionController extends Controller
{
    public function __construct(
        private readonly RegistrationCompletionService $registrationCompletionService,
        private readonly TutorAccountClaimService $tutorAccountClaimService,
    ) {}

    /**
     * Caminho manual de reivindicação (contrato §6): se o CPF informado já pertence a uma
     * conta não reivindicada, `TutorAccountClaimService` funde a conta-casca (autocadastro
     * recém-criado) nela ANTES de aplicar os dados do formulário — dali em diante o resto do
     * fluxo roda exatamente igual, só que sobre a conta com o histórico de verdade.
     */
    public function completeTutor(CompleteTutorRegistrationRequest $request)
    {
        $validated = $request->validated();
        $claim = $this->tutorAccountClaimService->resolve($request->user(), $validated['cpf']);

        $user = $this->registrationCompletionService->completeTutor(
            $claim->user,
            $validated,
            $request->file('avatar'),
        );

        return response()->json([
            'message' => 'Profile completed successfully!',
            'user' => $user,
            'access_token' => $claim->newAccessToken,
        ]);
    }

    /**
     * `professional_type` nunca é lido de novo do request nem redigitado aqui — o único
     * ponto de verdade é `ProfessionalType::tryFrom($user->user_type)`, o mesmo enum que
     * `RegisterRequest` já validou na etapa 1 do cadastro.
     *
     * O Form Request certo (vet ou profissional genérico) é resolvido manualmente porque a
     * rota é uma só (`/register/complete-professional`) e a escolha depende do tipo de conta
     * autenticada — `app()` dispara a mesma validação automática que a injeção via assinatura
     * do método dispararia numa rota direta (`FormRequestServiceProvider::boot()`).
     */
    public function completeProfessional(Request $request)
    {
        $user = $request->user();
        $professionalType = ProfessionalType::tryFrom($user->user_type);

        if ($professionalType === null) {
            return response()->json(['message' => 'Invalid user type'], 400);
        }

        return $professionalType === ProfessionalType::VET
            ? $this->completeVet($user, app(CompleteVetRegistrationRequest::class))
            : $this->completeGenericProfessional($user, $professionalType, app(CompleteGenericProfessionalRegistrationRequest::class));
    }

    private function completeVet(User $user, CompleteVetRegistrationRequest $request)
    {
        $user = $this->registrationCompletionService->completeVet($user, $request->validated());

        return response()->json([
            'message' => 'Vet profile completed successfully!',
            'user' => $user,
        ]);
    }

    private function completeGenericProfessional(User $user, ProfessionalType $professionalType, CompleteGenericProfessionalRegistrationRequest $request)
    {
        $result = $this->registrationCompletionService->completeGenericProfessional(
            $user,
            $professionalType,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Professional profile completed successfully!',
            'user' => $result['user'],
            'organization' => $result['organization'] !== null
                ? new OrganizationSummaryResource($result['organization'])
                : null,
            'technical_responsible_invitation_sent' => $result['technical_responsible_invitation_sent'],
        ]);
    }

    public function completeCompany(CompleteCompanyRegistrationRequest $request)
    {
        $user = $this->registrationCompletionService->completeCompany($request->user(), $request->validated());

        return response()->json([
            'message' => 'Company profile completed successfully!',
            'user' => $user,
        ]);
    }
}
