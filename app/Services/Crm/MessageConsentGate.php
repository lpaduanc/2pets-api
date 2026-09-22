<?php

namespace App\Services\Crm;

use App\Enums\MessageCategory;
use App\Enums\NotificationChannel;
use App\Models\User;

/**
 * Decide se um envio de CRM é barrado por falta de consentimento — contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`, regras de negócio 1 e 2.
 * Consentimento é do USUÁRIO (global na plataforma), nunca por-clínica; transacional nunca
 * depende de `consent_*_marketing`.
 */
final class MessageConsentGate
{
    public function denies(NotificationChannel $channel, MessageCategory $category, User $client): bool
    {
        return $category === MessageCategory::TRANSACTIONAL
            ? $this->transactionalDenied($channel, $client)
            : $this->marketingDenied($channel, $client);
    }

    private function transactionalDenied(NotificationChannel $channel, User $client): bool
    {
        return match ($channel) {
            NotificationChannel::SMS => ! $client->consent_sms_transactional,
            NotificationChannel::WHATSAPP => ! $client->consent_whatsapp_transactional,
            default => false,
        };
    }

    private function marketingDenied(NotificationChannel $channel, User $client): bool
    {
        return match ($channel) {
            NotificationChannel::SMS => ! $client->consent_sms_marketing,
            NotificationChannel::WHATSAPP => ! $client->consent_whatsapp_marketing,
            NotificationChannel::EMAIL => ! $client->marketing_consent,
            default => false,
        };
    }
}
