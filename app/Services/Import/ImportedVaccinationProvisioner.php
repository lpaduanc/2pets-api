<?php

namespace App\Services\Import;

use App\Models\Vaccination;

/**
 * Cria a dose de vacina importada (item 26 do backlog gap-simplesvet) — `professional_id`
 * fica em branco de propósito. Quem RODA a importação raramente é quem aplicou a dose no
 * sistema de origem, e a coluna já é nullable exatamente para não fabricar essa autoria
 * (mesma leitura já registrada na migration
 * `2026_09_06_000204_make_professional_id_nullable_on_vaccinations_and_surgeries`: "nor
 * should it — that would fabricate clinical data attributing the act to a professional who
 * wasn't involved").
 */
final class ImportedVaccinationProvisioner
{
    /**
     * @param  array{pet_id: int, vaccine_name: string, manufacturer: ?string, batch_number: ?string, application_date: string, expiry_date: ?string, next_dose_date: ?string, dose_number: ?int, notes: ?string}  $normalized
     */
    public function create(array $normalized): Vaccination
    {
        return Vaccination::create([
            'pet_id' => $normalized['pet_id'],
            'professional_id' => null,
            'vaccine_name' => $normalized['vaccine_name'],
            'manufacturer' => $normalized['manufacturer'],
            'batch_number' => $normalized['batch_number'],
            'application_date' => $normalized['application_date'],
            'expiry_date' => $normalized['expiry_date'],
            'next_dose_date' => $normalized['next_dose_date'],
            'dose_number' => $normalized['dose_number'],
            'notes' => $normalized['notes'],
        ]);
    }
}
