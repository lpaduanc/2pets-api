<?php

namespace App\Services\Import;

use App\Enums\Import\ImportRowStatus;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\Product;

/**
 * Executa a importação de produtos em lotes (item 26 do backlog gap-simplesvet, regra 5:
 * duplicidade por GTIN/código). Chunk/transação por lote vêm de
 * `AbstractChunkedImportExecutor`.
 */
final class ProductImportExecutor extends AbstractChunkedImportExecutor
{
    public function __construct(
        private readonly ProductDuplicateDetector $duplicateDetector,
        private readonly ImportedProductProvisioner $provisioner,
    ) {}

    protected function importRow(DataImportRow $row, DataImport $import, string $strategy): void
    {
        $actor = $import->user;
        $existing = $this->duplicateDetector->findExisting($row->normalized, $actor);

        if ($existing !== null && $strategy !== 'create_anyway') {
            $this->handleExistingMatch($row, $existing, $strategy);

            return;
        }

        $product = $this->provisioner->create($row->normalized, $actor);
        $row->update([
            'status' => ImportRowStatus::IMPORTED->value,
            'created_record_type' => Product::class,
            'created_record_id' => $product->id,
        ]);
    }

    private function handleExistingMatch(DataImportRow $row, Product $existing, string $strategy): void
    {
        if ($strategy === 'update') {
            $updatable = array_diff_key($row->normalized, array_flip(['stock_quantity']));
            $existing->update(array_filter($updatable, fn ($value): bool => $value !== null));
        }

        $row->update([
            'status' => ImportRowStatus::DUPLICATE->value,
            'matched_existing_id' => $existing->id,
        ]);
    }
}
