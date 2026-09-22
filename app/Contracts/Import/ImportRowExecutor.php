<?php

namespace App\Contracts\Import;

use App\Models\DataImport;

/**
 * Executor de importação por entidade (item 26 do backlog gap-simplesvet) — um por entidade
 * suportada (`ClientImportExecutor`, `PetImportExecutor`...), resolvido por
 * `App\Services\Import\ImportEntityHandlerRegistry` e chamado por
 * `App\Services\Import\ImportExecutionService` dentro do `ImportBatchJob`.
 */
interface ImportRowExecutor
{
    public function run(DataImport $import): void;
}
