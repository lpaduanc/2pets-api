<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Events\MedicalRecordFinalized;
use App\Notifications\InAppNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Convite de avaliação disparado pela finalização do prontuário (item 12 do MVP) —
 * reaproveita o canal de notificação existente (`InAppNotification` + `NotificationType`)
 * em vez de construir um mecanismo novo. `REVIEW_REQUEST` já existia no enum (rótulo
 * "Solicitação de avaliação") mas só era usado, de forma invertida, para avisar o
 * PROFISSIONAL que recebeu uma avaliação (`SendReviewNotification`) — este listener é o uso
 * literal do nome: pede ao TUTOR que avalie.
 *
 * Fila real: o worker `2pets-worker` (`queue:work`) está sempre rodando neste ambiente,
 * então este listener processa de verdade — não é fire-and-forget que nunca executa.
 */
class SendReviewInviteNotification implements ShouldQueue
{
    public function handle(MedicalRecordFinalized $event): void
    {
        $record = $event->medicalRecord->loadMissing(['pet.user', 'professional']);

        $tutor = $record->pet?->user;
        if (! $tutor) {
            return;
        }

        try {
            $tutor->notify(new InAppNotification(
                type: NotificationType::REVIEW_REQUEST,
                title: 'Avalie o atendimento',
                body: "Como foi o atendimento de {$record->pet->name} com {$record->professional->name}? Deixe sua avaliação.",
                data: [
                    'medical_record_id' => $record->id,
                    'appointment_id' => $record->appointment_id,
                    'professional_id' => $record->professional_id,
                    'pet_id' => $record->pet_id,
                ]
            ));
        } catch (\Exception $e) {
            Log::error('Failed to send review invite notification', [
                'medical_record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
