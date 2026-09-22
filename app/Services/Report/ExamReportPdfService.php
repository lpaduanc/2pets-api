<?php

namespace App\Services\Report;

use App\Models\Exam;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

final class ExamReportPdfService
{
    /**
     * @var list<string>
     */
    private const RELATIONS = ['professional.professional', 'pet.user', 'examType', 'results'];

    public function generate(Exam $exam): Response
    {
        $pdf = Pdf::loadView('pdfs.exam-report', [
            'exam' => $exam->load(self::RELATIONS),
        ]);

        return $pdf->download("laudo-exame-{$exam->id}.pdf");
    }
}
