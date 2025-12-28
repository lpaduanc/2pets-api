<?php

namespace App\Services\Report;

use App\Models\Pet;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

final class MedicalHistoryPdfService
{
    public function generate(Pet $pet): Response
    {
        $pdf = Pdf::loadView('pdfs.medical-history', [
            'pet' => $pet->load([
                'user',
                'medicalRecords.professional',
                'vaccinations',
                'medications',
                'prescriptions.professional',
            ]),
        ]);

        $filename = "medical-history-{$pet->name}.pdf";

        return $pdf->download($filename);
    }
}

