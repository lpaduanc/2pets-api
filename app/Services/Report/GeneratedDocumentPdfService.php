<?php

namespace App\Services\Report;

use App\Models\GeneratedDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

final class GeneratedDocumentPdfService
{
    /**
     * @var list<string>
     */
    private const RELATIONS = ['pet', 'issuedBy.professional', 'documentTemplate'];

    public function generate(GeneratedDocument $document): Response
    {
        $pdf = Pdf::loadView('pdfs.generated-document', [
            'document' => $document->load(self::RELATIONS),
        ]);

        return $pdf->download("documento-{$document->id}.pdf");
    }
}
