<?php

namespace App\Notifications;

use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Notifications\Support\FrontendRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Enviada ao VETERINÁRIO quando o tutor recusa a solicitação de acesso ao pet.
 *
 * Sem isso, o vet fica sem saber se o pedido ainda está pendente ou já foi recusado — descobre
 * só ao tentar abrir o paciente e não encontrar nada. `database`-only de propósito: recusa não
 * é urgente o bastante para justificar e-mail (diferente de `PetVetAccessRevoked`, que pode
 * indicar um problema em atendimento já em andamento).
 */
class PetVetAccessRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PetVetAccess $access,
        public readonly Pet $pet,
        public readonly User $tutor,
        public readonly ?string $reason = null,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'pet_vet_access_rejected',
            'title' => "Solicitação recusada para {$this->pet->name}",
            'message' => $this->reason === null
                ? "{$this->tutor->name} recusou sua solicitação de acesso."
                : "{$this->tutor->name} recusou sua solicitação de acesso: \"{$this->reason}\"",
            'action_url' => FrontendRoute::PROFESSIONAL_MY_PATIENTS,
            'data' => [
                'access_id' => $this->access->id,
                'pet_id' => $this->pet->id,
                'pet_name' => $this->pet->name,
                'tutor_id' => $this->tutor->id,
                'tutor_name' => $this->tutor->name,
                'rejection_reason' => $this->reason,
            ],
        ];
    }
}
