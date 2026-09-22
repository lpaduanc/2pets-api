<?php

namespace App\Services\Report;

use App\Models\ExamRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

final class ExamRequestPdfService
{
    /**
     * @var list<string>
     */
    private const RELATIONS = ['requestedBy.professional', 'pet.user', 'examTypes'];

    public function generate(ExamRequest $examRequest): Response
    {
        $pdf = Pdf::loadView('pdfs.exam-request', [
            'examRequest' => $examRequest->load(self::RELATIONS),
        ]);

        return $pdf->download("pedido-exame-{$examRequest->id}.pdf");
    }
}
