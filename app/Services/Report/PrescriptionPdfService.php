<?php

namespace App\Services\Report;

use App\Models\Prescription;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

final class PrescriptionPdfService
{
    /**
     * @var list<string>
     */
    private const RELATIONS = ['professional.professional', 'pet.user', 'items'];

    public function generate(Prescription $prescription): Response
    {
        $pdf = Pdf::loadView('pdfs.prescription', [
            'prescription' => $prescription->load(self::RELATIONS),
        ]);

        return $pdf->download("prescription-{$prescription->id}.pdf");
    }
}
