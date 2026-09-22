<?php

namespace App\Services\Import;

use App\Enums\Import\ImportRowStatus;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\User;

/**
 * Executa a importação de clientes em lotes (item 26 do backlog gap-simplesvet, regra 2:
 * duplicidade é decisão do usuário, nunca automática — `options.duplicate_strategy` foi
 * escolhido pelo usuário ANTES de chamar `execute`, nunca inferido aqui). Chunk/transação por
 * lote vêm de `AbstractChunkedImportExecutor`, compartilhado com as demais entidades.
 */
final class ClientImportExecutor extends AbstractChunkedImportExecutor
{
    public function __construct(
        private readonly ClientDuplicateDetector $duplicateDetector,
        private readonly ImportedClientProvisioner $provisioner,
    ) {}

    protected function importRow(DataImportRow $row, DataImport $import, string $strategy): void
    {
        $professionalId = $import->professional_id ?? $import->user_id;
        $existing = $this->duplicateDetector->findExisting($row->normalized);

        if ($existing !== null && $strategy !== 'create_anyway') {
            $this->handleExistingMatch($row, $existing, $strategy);

            return;
        }

        $client = $this->provisioner->create($row->normalized, $professionalId);
        $row->update([
            'status' => ImportRowStatus::IMPORTED->value,
            'created_record_type' => User::class,
            'created_record_id' => $client->id,
        ]);
    }

    private function handleExistingMatch(DataImportRow $row, User $existing, string $strategy): void
    {
        if ($strategy === 'update') {
            $existing->update(array_filter($row->normalized, fn ($value): bool => $value !== null));
        }

        $row->update([
            'status' => ImportRowStatus::DUPLICATE->value,
            'matched_existing_id' => $existing->id,
        ]);
    }
}
