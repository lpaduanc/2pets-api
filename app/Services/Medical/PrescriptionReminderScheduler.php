<?php

namespace App\Services\Medical;

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Reminder;
use Carbon\Carbon;

/**
 * Cria `Reminder` de dose ao emitir uma prescrição — doc de domínio
 * docs/atendimento-veterinario/02-receituario-dominio.md §5.2: "sempre, sem confirmação do
 * tutor" (é notificação, risco baixo). Reaproveita o model `Reminder` que o resto do produto
 * já usa (`App\Services\Reminder\HealthReminderService`), sem tabela nova.
 *
 * Duas situações em que o cronograma NÃO é previsível — cria só o primeiro lembrete e não
 * tenta adivinhar um fim, exatamente como o doc pede:
 *   - `is_continuous_use = true` ("uso contínuo" não tem data de parada);
 *   - `duration_text` não contém um número de dias interpretável ("até reavaliação").
 */
final class PrescriptionReminderScheduler
{
    /**
     * Teto de segurança por item: 30 dias em BID (2x/dia) já são 60 lembretes. Sem teto, uma
     * duração digitada como "365 dias" em QID geraria 1460 linhas por item.
     */
    private const MAX_REMINDERS_PER_ITEM = 60;

    private const DURATION_DAYS_PATTERN = '/(\d+)\s*dia/iu';

    public function scheduleForPrescription(Prescription $prescription): void
    {
        $prescription->loadMissing(['items', 'pet']);
        $tutorId = $prescription->pet?->user_id;

        if ($tutorId === null) {
            return;
        }

        foreach ($prescription->items as $item) {
            $this->scheduleForItem($item, $prescription, $tutorId);
        }
    }

    private function scheduleForItem(PrescriptionItem $item, Prescription $prescription, int $tutorId): void
    {
        $intervalHours = $item->frequency?->intervalHours($item->frequency_custom_hours);
        $doseCount = $this->resolveDoseCount($item, $intervalHours);
        $firstDueAt = Carbon::now();

        for ($dose = 0; $dose < $doseCount; $dose++) {
            $dueAt = $intervalHours !== null
                ? $firstDueAt->copy()->addHours($intervalHours * $dose)
                : $firstDueAt->copy();

            $this->createReminder($item, $prescription, $tutorId, $dueAt);
        }
    }

    private function createReminder(PrescriptionItem $item, Prescription $prescription, int $tutorId, Carbon $dueAt): void
    {
        Reminder::create([
            'user_id' => $tutorId,
            'pet_id' => $prescription->pet_id,
            'type' => 'medication',
            'title' => "Medicação: {$item->displayName()}",
            'description' => $this->description($item),
            'due_date' => $dueAt,
            'reminder_date' => $dueAt,
            'metadata' => [
                'prescription_id' => $prescription->id,
                'prescription_item_id' => $item->id,
            ],
        ]);
    }

    private function resolveDoseCount(PrescriptionItem $item, ?int $intervalHours): int
    {
        if ($item->is_continuous_use) {
            return 1;
        }

        $days = $this->parseDurationDays($item->duration_text);

        if ($days === null || $intervalHours === null) {
            return 1;
        }

        return min(self::MAX_REMINDERS_PER_ITEM, max(1, intdiv($days * 24, $intervalHours)));
    }

    private function parseDurationDays(?string $durationText): ?int
    {
        if ($durationText === null) {
            return null;
        }

        if (preg_match(self::DURATION_DAYS_PATTERN, $durationText, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function description(PrescriptionItem $item): string
    {
        $dose = $item->dose_value !== null && $item->dose_unit !== null
            ? "{$item->dose_value} {$item->dose_unit} de "
            : '';

        return "Administrar {$dose}{$item->displayName()}.";
    }
}
