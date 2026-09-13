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
 * Enviada ao VETERINÁRIO quando o tutor revoga um acesso já concedido.
 *
 * A mais crítica das três: sem aviso, o vet só descobre a revogação levando um 403 na frente
 * do cliente — possivelmente no meio de um atendimento. `mail` + `database`, como
 * `PetVetAccessRequested`, porque isso muda o que ele pode fazer agora, não é só um FYI.
 */
class PetVetAccessRevoked extends Notification implements ShouldQueue
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
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Acesso revogado a {$this->pet->name} — 2pets")
            ->greeting("Olá, {$notifiable->name}!")
            ->line("{$this->tutor->name} revogou seu acesso ao prontuário de **{$this->pet->name}**.");

        if ($this->reason !== null) {
            $mail->line("Motivo informado: \"{$this->reason}\"");
        }

        return $mail
            ->line('Você não tem mais acesso aos dados clínicos deste pet. Se precisar retomar o atendimento, solicite acesso novamente.')
            ->action('Ver meus pacientes', FrontendRoute::absolute(FrontendRoute::PROFESSIONAL_MY_PATIENTS));
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'pet_vet_access_revoked',
            'title' => "Acesso revogado a {$this->pet->name}",
            'message' => "{$this->tutor->name} revogou seu acesso a este pet.",
            'action_url' => FrontendRoute::PROFESSIONAL_MY_PATIENTS,
            'data' => [
                'access_id' => $this->access->id,
                'pet_id' => $this->pet->id,
                'pet_name' => $this->pet->name,
                'tutor_id' => $this->tutor->id,
                'tutor_name' => $this->tutor->name,
                'revocation_reason' => $this->reason,
            ],
        ];
    }
}
