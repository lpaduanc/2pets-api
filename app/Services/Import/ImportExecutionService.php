<?php

namespace App\Services\Import;

use App\Enums\Import\ImportStatus;
use App\Models\DataImport;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Roda a execução de uma importação já validada e finaliza o registro (item 26 do backlog
 * gap-simplesvet) — separado de `DataImportService` para não estourar o limite de dependências
 * do construtor dele (já em 6) só para servir o `ImportBatchJob`. Resolve o executor certo por
 * `ImportEntityHandlerRegistry`, o mesmo ponto único de extensão usado na validação.
 */
final class ImportExecutionService
{
    public function __construct(private readonly ImportEntityHandlerRegistry $handlerRegistry) {}

    public function run(int $dataImportId): void
    {
        $import = DataImport::findOrFail($dataImportId);

        try {
            $this->handlerRegistry->executorFor($import->entity)->run($import);
            $this->finalize($import, ImportStatus::COMPLETED);
        } catch (Throwable $exception) {
            Log::error('data-import.execution-failed', [
                'data_import_id' => $dataImportId,
                'exception' => $exception->getMessage(),
            ]);
            $this->finalize($import, ImportStatus::FAILED);

            throw $exception;
        }
    }

    private function finalize(DataImport $import, ImportStatus $status): void
    {
        $import->update([
            'imported_rows' => $import->rows()->imported()->count(),
            'status' => $status->value,
            'finished_at' => now(),
        ]);
    }
}
