<?php

namespace App\Notifications;

use App\Enums\VetAccessLevel;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Notifications\Support\FrontendRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Enviada ao VETERINÁRIO quando o tutor aprova a solicitação de acesso ao pet.
 *
 * Fecha o outro lado do handshake de `PetVetAccessRequested`: hoje o vet só descobre que foi
 * aprovado tentando abrir a tela de pacientes na sorte. O nível concedido é a informação que
 * muda o que ele pode fazer (`VetAccessLevel::canWriteClinicalRecords()`/`canManagePetRecord()`)
 * — por isso ele é sempre o destaque, nunca um detalhe de rodapé.
 *
 * Channels: mail + database, mesmo padrão de `PetVetAccessRequested`.
 */
class PetVetAccessApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PetVetAccess $access,
        public readonly Pet $pet,
        public readonly User $tutor,
        public readonly VetAccessLevel $grantedLevel,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Acesso liberado a {$this->pet->name} — 2pets")
            ->greeting("Olá, {$notifiable->name}!")
            ->line("{$this->tutor->name} liberou seu acesso ao prontuário de **{$this->pet->name}**.")
            ->line("Nível concedido: **{$this->grantedLevel->label()}** — {$this->grantedLevel->description()}")
            ->action('Ver meus pacientes', FrontendRoute::absolute(FrontendRoute::PROFESSIONAL_MY_PATIENTS))
            ->line('O tutor pode alterar o nível ou revogar o acesso a qualquer momento.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'pet_vet_access_approved',
            'title' => "Acesso liberado a {$this->pet->name}",
            'message' => "{$this->tutor->name} liberou seu acesso com nível {$this->grantedLevel->label()}.",
            'action_url' => FrontendRoute::PROFESSIONAL_MY_PATIENTS,
            'data' => [
                'access_id' => $this->access->id,
                'pet_id' => $this->pet->id,
                'pet_name' => $this->pet->name,
                'tutor_id' => $this->tutor->id,
                'tutor_name' => $this->tutor->name,
                'granted_access_level' => $this->grantedLevel->value,
            ],
        ];
    }
}
