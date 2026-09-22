<?php

namespace App\Http\Controllers\Api;

use App\Enums\Import\ImportEntity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Import\ExecuteDataImportRequest;
use App\Http\Requests\Import\StoreDataImportRequest;
use App\Http\Requests\Import\UpdateDataImportMappingRequest;
use App\Http\Resources\Import\DataImportResource;
use App\Models\DataImport;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Import\DataImportService;
use App\Services\Import\ImportErrorExporter;
use App\Services\Import\ImportTemplateProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `data-imports` — item 26 do backlog gap-simplesvet. Escopo por dono via
 * `CommercialScopeResolver`, mesmo padrão de `CatalogController`; toda rota já exige
 * `permission:data.import` (`routes/api.php`), nunca checado aqui dentro.
 */
class DataImportController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly DataImportService $importService,
        private readonly ImportErrorExporter $errorExporter,
        private readonly ImportTemplateProvider $templateProvider,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $imports = $this->scope->scopeQuery(DataImport::query(), $request->user())
            ->latest()
            ->paginate();

        return DataImportResource::collection($imports);
    }

    public function store(StoreDataImportRequest $request): JsonResponse
    {
        $import = $this->importService->upload($request->file('file'), $request->entity(), $request->user());

        return (new DataImportResource($import))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $id): DataImportResource
    {
        return new DataImportResource($this->findOwnedOrFail($id, $request));
    }

    public function updateMapping(UpdateDataImportMappingRequest $request, int $id): DataImportResource
    {
        $import = $this->findOwnedOrFail($id, $request);

        return new DataImportResource($this->importService->updateMapping($import, $request->mapping()));
    }

    public function validateRows(Request $request, int $id): JsonResponse
    {
        $import = $this->findOwnedOrFail($id, $request);

        return response()->json($this->importService->validate($import));
    }

    public function execute(ExecuteDataImportRequest $request, int $id): JsonResponse
    {
        $import = $this->findOwnedOrFail($id, $request);
        $this->importService->execute($import, $request->options());

        return response()->json(['message' => 'Importação iniciada.'], 202);
    }

    public function rollback(Request $request, int $id): JsonResponse
    {
        $import = $this->findOwnedOrFail($id, $request);

        return response()->json($this->importService->rollback($import));
    }

    public function exportErrors(Request $request, int $id): StreamedResponse
    {
        $import = $this->findOwnedOrFail($id, $request);
        $csv = $this->errorExporter->toCsv($import);

        return $this->csvDownload($csv, "importacao-{$id}-erros.csv");
    }

    public function template(string $entity): StreamedResponse
    {
        $csv = $this->templateProvider->toCsv(ImportEntity::from($entity));

        return $this->csvDownload($csv, "modelo-importacao-{$entity}.csv");
    }

    private function findOwnedOrFail(int $id, Request $request): DataImport
    {
        return $this->scope->scopeQuery(DataImport::query(), $request->user())->findOrFail($id);
    }

    private function csvDownload(string $csv, string $filename): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($csv): void {
                echo $csv;
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
