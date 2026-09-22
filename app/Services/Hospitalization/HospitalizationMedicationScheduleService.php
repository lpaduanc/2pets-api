<?php

namespace App\Services\Hospitalization;

use App\Models\Hospitalization;
use App\Models\PrescriptionItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * `GET hospitalizations/{id}/medication-schedule` — contrato docs/gap-simplesvet/specs/
 * 12-internacao-mapa-execucao-spec.md §3. "Próxima dose esperada" é SEMPRE calculada em
 * leitura (regra de negócio 4) — nenhuma linha de execução por dose é pré-gerada nem
 * persistida, e a lista inteira sai de UMA query agregada (sem N+1 por item).
 */
final class HospitalizationMedicationScheduleService
{
    /**
     * @return Collection<int, array{prescription_item: PrescriptionItem, last_given_at: ?Carbon, next_due_at: ?Carbon, is_late: bool}>
     */
    public function forHospitalization(Hospitalization $hospitalization): Collection
    {
        return $this->activeItems($hospitalization)
            ->map(fn (PrescriptionItem $item): array => $this->buildEntry($item))
            ->sortBy(fn (array $entry): int => $entry['next_due_at']?->timestamp ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * `withMax` resolve `last_given_at` como subquery correlata no próprio SELECT — uma
     * única ida ao banco, qualquer que seja o número de itens ativos.
     *
     * @return Collection<int, PrescriptionItem>
     */
    private function activeItems(Hospitalization $hospitalization): Collection
    {
        return PrescriptionItem::query()
            ->whereHas('prescription', function ($query) use ($hospitalization): void {
                $query->where('appointment_id', $hospitalization->appointment_id)
                    ->whereNull('canceled_at');
            })
            ->withMax('careLogs as last_given_at', 'performed_at')
            ->get();
    }

    /**
     * @return array{prescription_item: PrescriptionItem, last_given_at: ?Carbon, next_due_at: ?Carbon, is_late: bool}
     */
    private function buildEntry(PrescriptionItem $item): array
    {
        $lastGivenAt = $item->last_given_at !== null ? Carbon::parse($item->last_given_at) : null;
        $nextDueAt = $item->nextDueAt($lastGivenAt);

        return [
            'prescription_item' => $item,
            'last_given_at' => $lastGivenAt,
            'next_due_at' => $nextDueAt !== null ? Carbon::instance($nextDueAt) : null,
            'is_late' => $item->isMedicationLate($nextDueAt),
        ];
    }
}
