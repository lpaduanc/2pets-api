<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\Http\Controllers\Controller;
use App\Models\FiscalDocument;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Fiscal\FiscalDocumentFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download do XML autorizado (procNFe) e do DANFE/DANFCE — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §8. Rota com `fiscal-documents.view`; documento só do
 * escopo do usuário (404 fora dele) e `FiscalDocumentPolicy::view`. O arquivo sai do disco privado
 * em stream; cada download fica no log de atividade (quem, qual documento, qual arquivo).
 */
class FiscalDocumentFileController extends Controller
{
    private const CONTENT_TYPES = ['xml' => 'application/xml', 'pdf' => 'application/pdf'];

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly FiscalDocumentFiles $files,
    ) {}

    public function xml(Request $request, int $id): StreamedResponse
    {
        return $this->download($request, $id, 'xml');
    }

    public function pdf(Request $request, int $id): StreamedResponse
    {
        return $this->download($request, $id, 'pdf');
    }

    private function download(Request $request, int $id, string $type): StreamedResponse
    {
        $document = $this->scope->scopeQuery(FiscalDocument::query(), $request->user())->findOrFail($id);
        Gate::forUser($request->user())->authorize('view', $document);
        $path = $type === 'xml' ? $document->xml_path : $document->pdf_path;
        abort_unless($this->files->exists($path), 404, 'Este documento não tem arquivo disponível (emissão simulada ou ainda não transmitido).');

        activity('fiscal')->performedOn($document)->causedBy($request->user())->event('fiscal_document.downloaded')
            ->withProperties(['type' => $type])->log('Arquivo do documento fiscal baixado');

        return $this->files->disk()->download($path, sprintf('%s.%s', $document->access_key ?? "documento-fiscal-{$document->id}", $type), ['Content-Type' => self::CONTENT_TYPES[$type]]);
    }
}
