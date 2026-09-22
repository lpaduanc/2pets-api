<?php

namespace App\Services\Crm;

use App\DataTransferObjects\Crm\CrmMessageRequest;
use App\Enums\MessageDispatchStatus;
use App\Enums\NotificationChannel;
use App\Mail\CrmCampaignMail;
use App\Models\MessageDispatch;
use App\Services\Notification\SmsService;
use App\Services\Notification\WhatsAppService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Única porta de saída da mensageria de CRM — contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. Verifica dedup diário e consentimento
 * antes de enviar, delega o transporte para `WhatsAppService`/`SmsService`/`Mail` (nunca
 * duplica), e SEMPRE grava `message_dispatches` — inclusive quando bloqueado, para auditoria.
 */
final class CrmMessageDispatcher
{
    public function __construct(
        private readonly WhatsAppService $whatsApp,
        private readonly SmsService $sms,
        private readonly MessageBodyRenderer $renderer,
        private readonly MessageConsentGate $consentGate,
    ) {}

    /**
     * `null` = já disparado hoje para este par automação/cliente/pet (dedup silencioso, não é
     * erro) — regra de negócio 3 da spec.
     */
    public function dispatch(CrmMessageRequest $request): ?MessageDispatch
    {
        if ($this->alreadyDispatchedToday($request)) {
            return null;
        }

        $body = $this->renderer->render($request->template->body, $request->placeholders);

        if ($this->consentGate->denies($request->template->channel, $request->template->category, $request->client)) {
            return $this->record($request, $body, MessageDispatchStatus::BLOCKED_BY_CONSENT);
        }

        return $this->send($request, $body);
    }

    private function alreadyDispatchedToday(CrmMessageRequest $request): bool
    {
        if ($request->automation === null) {
            return false;
        }

        return MessageDispatch::query()
            ->where('automation_id', $request->automation->id)
            ->where('client_id', $request->client->id)
            ->where('pet_id', $request->pet?->id)
            ->whereDate('created_at', today())
            ->exists();
    }

    private function send(CrmMessageRequest $request, string $body): MessageDispatch
    {
        $sent = match ($request->template->channel) {
            NotificationChannel::SMS => $this->sms->send($request->client, $body),
            NotificationChannel::WHATSAPP => $this->whatsApp->send($request->client, $body),
            NotificationChannel::EMAIL => $this->sendEmail($request, $body),
            default => false,
        };

        return $this->record(
            $request,
            $body,
            $sent ? MessageDispatchStatus::SENT : MessageDispatchStatus::FAILED,
            $sent ? now() : null,
            $sent ? null : 'Canal sem credencial configurada ou envio recusado pelo provedor.',
        );
    }

    private function sendEmail(CrmMessageRequest $request, string $body): bool
    {
        if (empty($request->client->email)) {
            return false;
        }

        try {
            Mail::to($request->client->email)->send(new CrmCampaignMail(
                subjectLine: $request->template->subject ?? '2pets',
                bodyText: $body,
                unsubscribeUrl: $this->unsubscribeUrl($request),
            ));

            return true;
        } catch (Throwable $failure) {
            Log::error('Failed to send CRM e-mail', ['client_id' => $request->client->id, 'error' => $failure->getMessage()]);

            return false;
        }
    }

    private function unsubscribeUrl(CrmMessageRequest $request): ?string
    {
        if ($request->template->category->value !== 'marketing') {
            return null;
        }

        return URL::signedRoute('unsubscribe.marketing', ['user' => $request->client->id, 'channel' => 'email']);
    }

    private function record(CrmMessageRequest $request, string $body, MessageDispatchStatus $status, ?CarbonInterface $sentAt = null, ?string $failedReason = null): MessageDispatch
    {
        return MessageDispatch::create([
            'organization_id' => $request->template->organization_id,
            'professional_id' => $request->template->professional_id,
            'client_id' => $request->client->id,
            'pet_id' => $request->pet?->id,
            'channel' => $request->template->channel,
            'category' => $request->template->category,
            'template_id' => $request->template->id,
            'automation_id' => $request->automation?->id,
            'campaign_id' => $request->campaign?->id,
            'to_address' => $this->addressFor($request),
            'body_rendered' => $body,
            'status' => $status,
            'sent_at' => $sentAt,
            'failed_reason' => $failedReason,
            'created_at' => now(),
        ]);
    }

    private function addressFor(CrmMessageRequest $request): ?string
    {
        return match ($request->template->channel) {
            NotificationChannel::EMAIL => $request->client->email,
            NotificationChannel::SMS, NotificationChannel::WHATSAPP => $request->client->phone,
            default => null,
        };
    }
}
