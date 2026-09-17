<?php

namespace App\Services\Professional;

use App\Mail\PetRegisteredMail;
use App\Models\ConsentLog;
use App\Models\Pet;
use App\Models\User;
use App\Services\Registration\RegistrationContinuationTokenService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * E-mail e consentimento do fluxo de paciente novo — contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §7. Chamado APÓS o commit
 * da transação principal: falha de e-mail nunca desfaz um agendamento já persistido.
 */
final class NewPatientNotificationService
{
    private const MARKETING_CONSENT_KEY = 'marketing_email';

    private const CONSENT_SOURCE = 'professional_new_patient';

    public function __construct(
        private readonly RegistrationContinuationTokenService $continuationTokenService,
    ) {}

    /**
     * Devolve se o link de continuação foi enviado (contrato: campo `claim_link_sent` da
     * resposta 201). Sem e-mail informado, nada é enviado — o agendamento já seguiu normalmente
     * antes desta chamada.
     */
    public function notify(User $tutor, User $professional, Pet $pet, bool $marketingOptIn, ?string $ipAddress, ?string $userAgent): bool
    {
        if ($tutor->email === null) {
            return false;
        }

        $continuationUrl = $tutor->isUnclaimed()
            ? $this->buildContinuationUrl($this->continuationTokenService->issue($tutor))
            : null;

        if ($marketingOptIn) {
            $this->recordMarketingConsent($tutor, $ipAddress, $userAgent);
        }

        $this->sendMail($tutor, $professional, $pet, $continuationUrl, $marketingOptIn);

        return $continuationUrl !== null;
    }

    private function buildContinuationUrl(string $plainToken): string
    {
        $appUrl = rtrim(config('app.frontend_url') ?? config('app.url'), '/');

        return "{$appUrl}/register/continue/{$plainToken}";
    }

    /**
     * Grava a prova de consentimento ANTES do disparo do e-mail (contrato §7 — art. 8º LGPD,
     * ônus da prova é do controlador) e reflete a decisão no gate real usado pelo resto do
     * sistema (`users.marketing_consent`). Nunca fizemos o inverso (marcar `marketing_consent`
     * sem a linha em `ConsentLog`) — sem o log, não há como provar a base legal depois.
     */
    private function recordMarketingConsent(User $tutor, ?string $ipAddress, ?string $userAgent): void
    {
        ConsentLog::create([
            'user_id' => $tutor->id,
            'consent_key' => self::MARKETING_CONSENT_KEY,
            'granted' => true,
            'source' => self::CONSENT_SOURCE,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'occurred_at' => now(),
        ]);

        $tutor->update(['marketing_consent' => true]);
    }

    /**
     * Não-bloqueante: mesmo padrão de `ClientProvisioningService`/`PasswordResetLinkService` —
     * o agendamento já foi persistido, uma falha de SMTP não pode desfazer o que já aconteceu.
     */
    private function sendMail(User $tutor, User $professional, Pet $pet, ?string $continuationUrl, bool $marketingOptIn): void
    {
        try {
            Mail::to($tutor->email)->send(new PetRegisteredMail(
                tutorName: $tutor->name,
                professionalLabel: $this->professionalLabel($professional),
                petName: $pet->name,
                continuationUrl: $continuationUrl,
                unsubscribeUrl: $this->buildUnsubscribeUrl($tutor),
                marketingOptIn: $marketingOptIn,
            ));
        } catch (Throwable $failure) {
            Log::error('Failed to send new-patient registration email', [
                'tutor_id' => $tutor->id,
                'error' => $failure->getMessage(),
            ]);
        }
    }

    private function professionalLabel(User $professional): string
    {
        return $professional->professional?->business_name ?? $professional->name;
    }

    /**
     * Signed route, sem `auth:sanctum` — descadastro em um clique não pode exigir login
     * (mesmo raciocínio de `documents/{document}/file`, que também usa `signed` puro).
     */
    private function buildUnsubscribeUrl(User $tutor): string
    {
        return URL::signedRoute('unsubscribe.marketing', ['user' => $tutor->id]);
    }
}
