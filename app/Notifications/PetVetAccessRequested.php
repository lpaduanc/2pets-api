<?php

namespace App\Notifications;

use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Notifications\Support\FrontendRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the tutor when a vet requests access to one of their pets.
 *
 * Channels:
 *   - `mail`: e-mail with approve/reject deep link.
 *   - `database`: in-app notification feed.
 *
 * Push (FCM) is handled separately by NotificationService if/when a device token
 * is registered — we don't duplicate it here to keep the notification self-contained
 * and testable with Notification::fake().
 */
class PetVetAccessRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PetVetAccess $access,
        public readonly Pet $pet,
        public readonly User $vet,
        public readonly ?string $crmv = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        // $this->crmv já vem no formato canônico com o prefixo embutido (ex.: "CRMV/SP
        // 45871", via CrmvValidationService::displayLabel) — prefixar "CRMV" de novo aqui
        // duplicava o rótulo ("CRMV CRMV/SP 45871/SP").
        $crmvLine = $this->crmv ? " ({$this->crmv})" : '';
        $requestedAt = $this->access->requested_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i');

        $requested = $this->access->requested_access_level;

        $mail = (new MailMessage)
            ->subject("Novo pedido de acesso veterinário a {$this->pet->name} — 2pets")
            ->greeting("Olá, {$notifiable->name}!")
            ->line("O(a) veterinário(a) **{$this->vet->name}**{$crmvLine} solicitou acesso ao prontuário de **{$this->pet->name}** em {$requestedAt}.")
            ->line("Nível indicado pelo profissional: **{$requested?->label()}** — {$requested?->description()}");

        if ($this->access->message !== null) {
            $mail->line("Mensagem do profissional: \"{$this->access->message}\"");
        }

        return $mail
            ->line('Quem decide o nível é você, na hora de aprovar, e pode alterá-lo depois. Só após sua aprovação os dados clínicos do seu pet serão compartilhados; você pode revogar o acesso a qualquer momento.')
            ->action('Ver solicitação', FrontendRoute::absolute(FrontendRoute::TUTOR_VET_ACCESS_REQUESTS))
            ->line('Se você não reconhece este pedido, clique em recusar.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'pet_vet_access_requested',
            'title' => "Pedido de acesso a {$this->pet->name}",
            'message' => "{$this->vet->name} solicitou acesso aos dados do seu pet.",
            'action_url' => FrontendRoute::TUTOR_VET_ACCESS_REQUESTS,
            'data' => [
                'access_id' => $this->access->id,
                'pet_id' => $this->pet->id,
                'pet_name' => $this->pet->name,
                'vet_id' => $this->vet->id,
                'vet_name' => $this->vet->name,
                'crmv' => $this->crmv,
                'requested_access_level' => $this->access->requested_access_level?->value,
                'message' => $this->access->message,
                'requested_at' => $this->access->requested_at?->toISOString(),
            ],
        ];
    }
}
