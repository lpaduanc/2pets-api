<?php

namespace App\Services\Report;

use App\Models\Prescription;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

final class PrescriptionPdfService
{
    /**
     * `medications` é coluna JSON, não relação — pedi-la em `load()` estourava
     * `BadMethodCallException: Call to undefined relationship [medications]`, ou seja, o
     * download de receita respondia 500 em toda chamada.
     *
     * @var list<string>
     */
    private const RELATIONS = ['professional.professional', 'pet.user'];

    public function generate(Prescription $prescription): Response
    {
        $pdf = Pdf::loadView('pdfs.prescription', [
            'prescription' => $prescription->load(self::RELATIONS),
            'medications' => $this->medicationList($prescription),
        ]);

        return $pdf->download("prescription-{$prescription->id}.pdf");
    }

    /**
     * Mesma defesa da `PrescriptionResource`: linha legada duplo-encodada devolve string no
     * cast `array`, e o Blade renderizaria caractere a caractere.
     *
     * @return list<array<string, mixed>>
     */
    private function medicationList(Prescription $prescription): array
    {
        $medications = $prescription->medications;

        if (is_string($medications)) {
            $medications = json_decode($medications, true);
        }

        if (! is_array($medications) || $medications === []) {
            return [];
        }

        return array_is_list($medications) ? $medications : [$medications];
    }
}
