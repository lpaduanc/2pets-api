<?php

namespace App\Jobs;

use App\Services\Import\ImportExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Executa uma importação de planilha em lote (item 26 do backlog gap-simplesvet) — despachado
 * por `DataImportService::execute()` na fila já existente (`docker-compose.yml`, serviço
 * `queue`). Wrapper assíncrono puro: a regra de negócio (chunk, transação, duplicidade,
 * finalização) mora em `ImportExecutionService`/`ClientImportExecutor`.
 */
class ImportBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(private readonly int $dataImportId) {}

    public function handle(ImportExecutionService $executionService): void
    {
        $executionService->run($this->dataImportId);
    }
}
