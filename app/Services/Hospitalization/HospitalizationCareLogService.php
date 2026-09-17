<?php

namespace App\Services\Hospitalization;

use App\Models\Hospitalization;
use App\Models\HospitalizationCareLog;
use App\Models\User;

/**
 * Contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md §4.
 *
 * Autorização (`HospitalizationPolicy::writeCareLog`) e a validação de "motivo obrigatório
 * quando `not_done`" (`StoreHospitalizationCareLogRequest`) já aconteceram antes de chegar
 * aqui — este service só garante que a estadia continua `active` (§7).
 */
final class HospitalizationCareLogService
{
    /**
     * @param  array{care_type: string, status: string, performed_at: ?string, notes: ?string, prescription_item_id: ?int}  $data
     */
    public function create(Hospitalization $hospitalization, User $author, array $data): HospitalizationCareLog
    {
        $this->assertActive($hospitalization);

        return HospitalizationCareLog::create([
            'hospitalization_id' => $hospitalization->id,
            'author_id' => $author->id,
            'care_type' => $data['care_type'],
            'status' => $data['status'],
            'performed_at' => $data['performed_at'] ?? now(),
            'notes' => $data['notes'] ?? null,
            'prescription_item_id' => $data['prescription_item_id'] ?? null,
        ]);
    }

    private function assertActive(Hospitalization $hospitalization): void
    {
        if (! $hospitalization->isActive()) {
            abort(422, 'Só é possível registrar cuidado numa internação ativa.');
        }
    }
}
