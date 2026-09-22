<?php

namespace App\Services\Import;

use App\Enums\Import\ImportRowStatus;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\Pet;

/**
 * Executa a importação de pets em lotes (item 26 do backlog gap-simplesvet, regra 5:
 * duplicidade por tutor + nome + espécie). Chunk/transação por lote vêm de
 * `AbstractChunkedImportExecutor`.
 */
final class PetImportExecutor extends AbstractChunkedImportExecutor
{
    public function __construct(
        private readonly PetDuplicateDetector $duplicateDetector,
        private readonly ImportedPetProvisioner $provisioner,
    ) {}

    protected function importRow(DataImportRow $row, DataImport $import, string $strategy): void
    {
        $normalized = $row->normalized;
        $existing = $this->duplicateDetector->findExisting(
            (int) $normalized['tutor_user_id'],
            $normalized['name'],
            $normalized['species'],
        );

        if ($existing !== null && $strategy !== 'create_anyway') {
            $this->handleExistingMatch($row, $existing, $strategy);

            return;
        }

        $pet = $this->provisioner->create($normalized);
        $row->update([
            'status' => ImportRowStatus::IMPORTED->value,
            'created_record_type' => Pet::class,
            'created_record_id' => $pet->id,
        ]);
    }

    private function handleExistingMatch(DataImportRow $row, Pet $existing, string $strategy): void
    {
        if ($strategy === 'update') {
            $existing->update(array_filter(
                $row->normalized,
                fn ($value, string $key): bool => $value !== null && ! in_array($key, ['tutor_user_id'], true),
                ARRAY_FILTER_USE_BOTH,
            ));
        }

        $row->update([
            'status' => ImportRowStatus::DUPLICATE->value,
            'matched_existing_id' => $existing->id,
        ]);
    }
}
