<?php

namespace App\Services\Hospitalization;

use App\Exceptions\Hospitalization\PrescriptionItemOutOfStayException;
use App\Models\Hospitalization;
use App\Models\Prescription;
use Illuminate\Support\Carbon;

/**
 * Regra de negócio 3 da spec docs/gap-simplesvet/specs/12-internacao-mapa-execucao-spec.md:
 * `prescription_items.starts_at`, quando informado numa prescrição pendurada numa internação
 * (`prescriptions.appointment_id` = o mesmo `appointment_id` da internação), não pode cair
 * antes da admissão nem depois da alta. Prescrição standalone (sem internação) não passa por
 * nenhuma checagem aqui — `starts_at` sem internação é só um dado informativo.
 */
final class HospitalizationStayGuard
{
    /**
     * @param  list<array<string, mixed>>  $itemsData
     *
     * @throws PrescriptionItemOutOfStayException
     */
    public function assertItemsStartWithinStay(Prescription $prescription, array $itemsData): void
    {
        $hospitalization = $this->hospitalizationFor($prescription);

        if ($hospitalization === null) {
            return;
        }

        foreach ($itemsData as $itemData) {
            $startsAt = $itemData['starts_at'] ?? null;

            if ($startsAt !== null) {
                $this->assertWithinWindow($hospitalization, Carbon::parse($startsAt));
            }
        }
    }

    private function hospitalizationFor(Prescription $prescription): ?Hospitalization
    {
        if ($prescription->appointment_id === null) {
            return null;
        }

        return Hospitalization::query()->where('appointment_id', $prescription->appointment_id)->first();
    }

    private function assertWithinWindow(Hospitalization $hospitalization, Carbon $startsAt): void
    {
        if ($startsAt->lt(Carbon::parse($hospitalization->admission_date)->startOfDay())) {
            throw PrescriptionItemOutOfStayException::beforeAdmission();
        }

        if ($hospitalization->discharge_date !== null
            && $startsAt->gt(Carbon::parse($hospitalization->discharge_date)->endOfDay())) {
            throw PrescriptionItemOutOfStayException::afterDischarge();
        }
    }
}
