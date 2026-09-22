<?php

namespace App\Services\Professional;

use App\Exceptions\Professional\ClientAlreadyActiveException;
use App\Exceptions\Professional\ClientWithoutEmailException;
use App\Mail\ClientInviteMail;
use App\Models\User;
use App\Services\Registration\RegistrationContinuationTokenService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Convite de vínculo FORA do fluxo de agendamento — contrato
 * `docs/gap-simplesvet/specs/19-portal-do-cliente-spec.md` (Achado 1). Reaproveita
 * `RegistrationContinuationTokenService` (mesmo mecanismo de
 * `NewPatientNotificationService`), nunca um segundo tipo de token.
 */
final class ClientInviteService
{
    public function __construct(
        private readonly RegistrationContinuationTokenService $continuationTokenService,
    ) {}

    /**
     * Reenvio invalida qualquer link anterior (comportamento de `issue()`, já implementado).
     *
     * @throws ClientAlreadyActiveException cliente já reivindicou a própria conta.
     * @throws ClientWithoutEmailException sem e-mail não há canal para entregar o convite.
     */
    public function invite(User $professional, User $client): void
    {
        $this->guardClientCanBeInvited($client);

        $continuationUrl = $this->buildContinuationUrl($this->continuationTokenService->issue($client));

        $this->sendMail($professional, $client, $continuationUrl);
    }

    private function guardClientCanBeInvited(User $client): void
    {
        if (! $client->isUnclaimed()) {
            throw new ClientAlreadyActiveException;
        }

        if ($client->email === null) {
            throw new ClientWithoutEmailException;
        }
    }

    private function buildContinuationUrl(string $plainToken): string
    {
        $appUrl = rtrim(config('app.frontend_url') ?? config('app.url'), '/');

        return "{$appUrl}/register/continue/{$plainToken}";
    }

    /**
     * Não-bloqueante: mesmo padrão de `NewPatientNotificationService` — o vínculo já foi
     * criado/atualizado, uma falha de SMTP não pode desfazer isso.
     */
    private function sendMail(User $professional, User $client, string $continuationUrl): void
    {
        try {
            Mail::to($client->email)->send(new ClientInviteMail(
                clientName: $client->name,
                professionalLabel: $this->professionalLabel($professional),
                continuationUrl: $continuationUrl,
                unsubscribeUrl: URL::signedRoute('unsubscribe.marketing', ['user' => $client->id]),
            ));
        } catch (Throwable $failure) {
            Log::error('Failed to send client invite email', [
                'client_id' => $client->id,
                'professional_id' => $professional->id,
                'error' => $failure->getMessage(),
            ]);
        }
    }

    private function professionalLabel(User $professional): string
    {
        return $professional->professional?->business_name ?? $professional->name;
    }
}
