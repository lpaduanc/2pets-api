<?php

namespace App\Services\Import;

use App\Contracts\Import\ImportRowExecutor;
use App\Enums\Import\ImportRowStatus;
use App\Models\DataImport;
use App\Models\DataImportRow;
use Illuminate\Support\Facades\DB;

/**
 * Esqueleto comum de execução em lote (item 26 do backlog gap-simplesvet, regra 2:
 * duplicidade é decisão do usuário, nunca automática — `options.duplicate_strategy` foi
 * escolhido ANTES de chamar `execute`). Chunk de 500 + transação por lote, igual para
 * `clients`/`pets`/`products`/`vaccinations`; só `importRow()` muda por entidade.
 */
abstract class AbstractChunkedImportExecutor implements ImportRowExecutor
{
    private const CHUNK_SIZE = 500;

    private const DEFAULT_STRATEGY = 'skip';

    public function run(DataImport $import): void
    {
        $strategy = (string) ($import->options['duplicate_strategy'] ?? self::DEFAULT_STRATEGY);

        $import->rows()->where('status', ImportRowStatus::VALID->value)
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($import, $strategy): void {
                DB::transaction(function () use ($rows, $import, $strategy): void {
                    foreach ($rows as $row) {
                        $this->importRow($row, $import, $strategy);
                    }
                });
            });
    }

    abstract protected function importRow(DataImportRow $row, DataImport $import, string $strategy): void;
}
