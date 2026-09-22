<?php

namespace App\Services\Import;

use App\Enums\Import\ImportRowStatus;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\Vaccination;

/**
 * Executa a importação de histórico de vacina em lotes (item 26 do backlog gap-simplesvet).
 * Chunk/transação por lote vêm de `AbstractChunkedImportExecutor`. Vacina é histórico
 * append-only (ver `VaccinationDuplicateDetector`) — estratégia `update` não faz sentido
 * clínico aqui (reaplicar uma dose não "corrige" a anterior), então é tratada como `skip`.
 */
final class VaccinationImportExecutor extends AbstractChunkedImportExecutor
{
    public function __construct(
        private readonly VaccinationDuplicateDetector $duplicateDetector,
        private readonly ImportedVaccinationProvisioner $provisioner,
    ) {}

    protected function importRow(DataImportRow $row, DataImport $import, string $strategy): void
    {
        $existing = $this->duplicateDetector->findExisting($row->normalized);

        if ($existing !== null && $strategy !== 'create_anyway') {
            $row->update(['status' => ImportRowStatus::DUPLICATE->value, 'matched_existing_id' => $existing->id]);

            return;
        }

        $vaccination = $this->provisioner->create($row->normalized);
        $row->update([
            'status' => ImportRowStatus::IMPORTED->value,
            'created_record_type' => Vaccination::class,
            'created_record_id' => $vaccination->id,
        ]);
    }
}
