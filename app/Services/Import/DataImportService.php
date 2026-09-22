<?php

namespace App\Services\Import;

use App\Contracts\Import\ImportRowValidator;
use App\Enums\Import\ImportEntity;
use App\Enums\Import\ImportRowStatus;
use App\Enums\Import\ImportStatus;
use App\Exceptions\Import\ImportEntityNotSupportedException;
use App\Jobs\ImportBatchJob;
use App\Models\DataImport;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Orquestra o ciclo de vida de uma importação (item 26 do backlog gap-simplesvet). Regra de
 * negócio fica aqui; parsing/validação/execução/rollback ficam nas classes especializadas
 * injetadas — este serviço só coordena a sequência e persiste o estado em `DataImport`.
 */
final class DataImportService
{
    public function __construct(
        private readonly ImportFileParser $fileParser,
        private readonly ColumnMapper $columnMapper,
        private readonly ImportEntityHandlerRegistry $handlerRegistry,
        private readonly ImportDependencyOrderGuard $dependencyOrderGuard,
        private readonly ImportRollbackService $rollbackService,
        private readonly CommercialScopeResolver $scopeResolver,
    ) {}

    public function upload(UploadedFile $file, ImportEntity $entity, User $user): DataImport
    {
        if (! $entity->isSupported()) {
            throw new ImportEntityNotSupportedException($entity);
        }

        $this->dependencyOrderGuard->ensureCanImport($entity, $user->id);

        $parsed = $this->fileParser->parse($file->getRealPath());
        $import = $this->createImportRecord($file, $entity, $user, count($parsed['rows']));

        $this->storeRawRows($import, $parsed['rows']);
        $import->update([
            'raw_headers' => $parsed['headers'],
            'column_mapping' => $this->columnMapper->suggest($parsed['headers'], $entity),
        ]);

        return $import;
    }

    /**
     * @param  array<string, string>  $mapping  coluna da planilha => campo canônico
     */
    public function updateMapping(DataImport $import, array $mapping): DataImport
    {
        $import->update(['column_mapping' => $mapping, 'status' => ImportStatus::MAPPING->value]);

        return $import;
    }

    /**
     * @return array{valid_rows: int, error_rows: int, sample_errors: list<array<string, mixed>>}
     */
    public function validate(DataImport $import): array
    {
        $import->update(['status' => ImportStatus::VALIDATING->value]);
        $mapping = $import->column_mapping ?? [];
        $validator = $this->handlerRegistry->validatorFor($import->entity);

        [$validCount, $errorCount, $sampleErrors] = $this->validateEachRow($import, $mapping, $validator);

        $import->update([
            'valid_rows' => $validCount,
            'error_rows' => $errorCount,
            'status' => $validCount > 0 ? ImportStatus::READY->value : ImportStatus::FAILED->value,
        ]);

        return ['valid_rows' => $validCount, 'error_rows' => $errorCount, 'sample_errors' => $sampleErrors];
    }

    /**
     * @param  array{duplicate_strategy?: string}  $options
     */
    public function execute(DataImport $import, array $options): void
    {
        $import->update([
            'status' => ImportStatus::IMPORTING->value,
            'options' => $options,
            'started_at' => now(),
        ]);

        ImportBatchJob::dispatch($import->id);
    }

    /**
     * @return array{rolled_back: int, refused: list<array{row_id: int, reason: string}>}
     */
    public function rollback(DataImport $import): array
    {
        $result = $this->rollbackService->rollback($import);
        $import->update(['status' => ImportStatus::ROLLED_BACK->value]);

        return $result;
    }

    private function createImportRecord(UploadedFile $file, ImportEntity $entity, User $user, int $totalRows): DataImport
    {
        return DataImport::create([
            ...$this->scopeResolver->ownershipFor($user),
            'user_id' => $user->id,
            'source' => 'csv',
            'entity' => $entity->value,
            'file_path' => $file->store('data-imports', 'local'),
            'status' => ImportStatus::UPLOADED->value,
            'total_rows' => $totalRows,
            'batch_uuid' => (string) Str::uuid(),
        ]);
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function storeRawRows(DataImport $import, array $rows): void
    {
        foreach ($rows as $index => $row) {
            $import->rows()->create([
                'row_number' => $index + 1,
                'raw' => $row,
                'status' => ImportRowStatus::PENDING->value,
            ]);
        }
    }

    /**
     * @param  array<string, string>  $mapping
     * @return array{0: int, 1: int, 2: list<array<string, mixed>>}
     */
    private function validateEachRow(DataImport $import, array $mapping, ImportRowValidator $validator): array
    {
        $validCount = 0;
        $errorCount = 0;
        $sampleErrors = [];

        foreach ($import->rows()->get() as $row) {
            $remapped = $this->remapRow($row->raw, $mapping);
            $result = $validator->validate($remapped, $import);

            $row->update([
                'normalized' => $result['normalized'],
                'errors' => $result['errors'],
                'status' => $result['valid'] ? ImportRowStatus::VALID->value : ImportRowStatus::INVALID->value,
            ]);

            if ($result['valid']) {
                $validCount++;
            } else {
                $errorCount++;
                $sampleErrors[] = ['row_number' => $row->row_number, 'errors' => $result['errors']];
            }
        }

        return [$validCount, $errorCount, array_slice($sampleErrors, 0, 20)];
    }

    /**
     * @param  array<string, string>  $raw
     * @param  array<string, string>  $mapping
     * @return array<string, string>
     */
    private function remapRow(array $raw, array $mapping): array
    {
        $remapped = [];
        foreach ($mapping as $column => $field) {
            $remapped[$field] = $raw[$column] ?? '';
        }

        return $remapped;
    }
}
