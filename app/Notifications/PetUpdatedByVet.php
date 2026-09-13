<?php

namespace App\Notifications;

use App\Models\Pet;
use App\Models\User;
use App\Notifications\Support\FrontendRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the tutor when a vet with an active grant touches the pet record or any
 * of its health sub-resources. The vet has write-level authority (per granted
 * access) — but the tutor is the legal owner and deserves visibility.
 *
 * Accountability pattern (not gating): we never block the vet's action in this
 * notification; it's disclosure, not consent. The audit log endpoint is the
 * long-term source of truth; this notification is the real-time nudge.
 *
 * Event types (in `data.type`):
 *   - `pet_info`             → vet edited the pet row itself
 *   - `health_added`         → vet added a vaccination/med/etc.
 *   - `health_edited`        → vet updated an existing health record
 *   - `medication_deactivated` → vet marked a continuous med as inactive
 *
 * Channels: mail + database. Push (FCM) is layered on top by NotificationService
 * when a device token is present.
 */
class PetUpdatedByVet extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Pet $pet,
        public readonly User $vet,
        public readonly string $eventType,
        /** @var array<string,mixed> */
        public readonly array $payload = [],
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $title = $this->mailTitle();
        $deepLink = FrontendRoute::absolute(FrontendRoute::tutorPetAuthorizedVets($this->pet->id));

        $mail = (new MailMessage)
            ->subject("Atualização no perfil de {$this->pet->name} — 2pets")
            ->greeting("Olá, {$notifiable->name}!")
            ->line($title);

        // Add context bullets when we have them — keeps the mail useful instead of noisy.
        if (! empty($this->payload['field_summary']) && is_array($this->payload['field_summary'])) {
            $fields = collect($this->payload['field_summary'])->take(6)->implode(', ');
            $mail->line("Campos alterados: {$fields}.");
        }
        if (! empty($this->payload['resource_type'])) {
            $mail->line("Tipo de registro: {$this->resourceLabel($this->payload['resource_type'])}.");
        }

        return $mail
            ->line('Você pode revisar o histórico completo e, se necessário, revogar o acesso deste profissional.')
            ->action('Ver acessos e histórico', $deepLink)
            ->line('Se você não reconhece esta alteração, revogue o acesso imediatamente e entre em contato com o suporte.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'pet_updated_by_vet',
            'title' => $this->shortTitle(),
            'message' => $this->shortMessage(),
            'action_url' => FrontendRoute::tutorPetAuthorizedVets($this->pet->id),
            'data' => [
                'pet_id' => $this->pet->id,
                'pet_name' => $this->pet->name,
                'vet_id' => $this->vet->id,
                'vet_name' => $this->vet->name,
                'event_type' => $this->eventType,
                'payload' => $this->payload,
            ],
        ];
    }

    private function mailTitle(): string
    {
        return match ($this->eventType) {
            'pet_info' => "O(a) veterinário(a) **{$this->vet->name}** atualizou dados do perfil de **{$this->pet->name}**.",
            'health_added' => "O(a) veterinário(a) **{$this->vet->name}** adicionou um novo registro de saúde ao perfil de **{$this->pet->name}**.",
            'health_edited' => "O(a) veterinário(a) **{$this->vet->name}** editou um registro de saúde de **{$this->pet->name}**.",
            'medication_deactivated' => "O(a) veterinário(a) **{$this->vet->name}** desativou um medicamento de **{$this->pet->name}**.",
            default => "O(a) veterinário(a) **{$this->vet->name}** alterou dados de **{$this->pet->name}**.",
        };
    }

    private function shortTitle(): string
    {
        return match ($this->eventType) {
            'pet_info' => "Perfil de {$this->pet->name} atualizado",
            'health_added' => "Novo registro de saúde para {$this->pet->name}",
            'health_edited' => 'Registro de saúde editado',
            'medication_deactivated' => 'Medicamento desativado',
            default => "Atualização em {$this->pet->name}",
        };
    }

    private function shortMessage(): string
    {
        $vet = $this->vet->name;

        return match ($this->eventType) {
            'pet_info' => "{$vet} alterou dados do perfil.",
            'health_added' => "{$vet} adicionou um registro de saúde.",
            'health_edited' => "{$vet} editou um registro de saúde.",
            'medication_deactivated' => "{$vet} desativou um medicamento.",
            default => "{$vet} alterou dados do pet.",
        };
    }

    private function resourceLabel(string $type): string
    {
        return match ($type) {
            'vaccinations' => 'vacinação',
            'dewormings' => 'vermifugação',
            'medications' => 'medicamento',
            'surgeries' => 'cirurgia',
            'exams' => 'exame',
            'hospitalizations' => 'internação',
            default => $type,
        };
    }
}
