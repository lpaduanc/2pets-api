<?php

namespace App\Services\Report;

use App\Models\Prescription;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

final class PrescriptionPdfService
{
    public function generate(Prescription $prescription): Response
    {
        $pdf = Pdf::loadView('pdfs.prescription', [
            'prescription' => $prescription->load([
                'professional',
                'pet.user',
                'medications',
            ]),
        ]);

        $filename = "prescription-{$prescription->id}.pdf";

        return $pdf->download($filename);
    }
}

