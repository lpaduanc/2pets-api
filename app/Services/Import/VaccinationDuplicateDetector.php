<?php

namespace App\Services\Import;

use App\Models\Vaccination;

/**
 * Dose de vacina por (pet + nome da vacina + data de aplicação) — item 26 do backlog
 * gap-simplesvet. O histórico é append-only por natureza (`Vaccination::scopeUpcoming()`
 * já documenta isso), então "duplicado" aqui é literalmente a mesma dose relançada, não uma
 * dose seguinte da mesma vacina.
 */
final class VaccinationDuplicateDetector
{
    /**
     * @param  array{pet_id: ?int, vaccine_name: string, application_date: ?string}  $normalized
     */
    public function findExisting(array $normalized): ?Vaccination
    {
        if ($normalized['pet_id'] === null || $normalized['application_date'] === null) {
            return null;
        }

        return Vaccination::query()
            ->where('pet_id', $normalized['pet_id'])
            ->where('application_date', $normalized['application_date'])
            ->whereRaw('lower(vaccine_name) = ?', [mb_strtolower($normalized['vaccine_name'])])
            ->first();
    }
}
