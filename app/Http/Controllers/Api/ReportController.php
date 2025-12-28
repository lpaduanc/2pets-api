<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Pet;
use App\Models\Prescription;
use App\Services\Report\InvoicePdfService;
use App\Services\Report\MedicalHistoryPdfService;
use App\Services\Report\PrescriptionPdfService;
use App\Services\Report\RevenueReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly InvoicePdfService $invoicePdfService,
        private readonly PrescriptionPdfService $prescriptionPdfService,
        private readonly MedicalHistoryPdfService $medicalHistoryPdfService,
        private readonly RevenueReportService $revenueService
    ) {}

    public function downloadInvoice(Request $request, int $invoiceId): Response
    {
        $invoice = Invoice::findOrFail($invoiceId);

        // Verify access
        $user = $request->user();
        if ($invoice->professional_id !== $user->id && $invoice->client_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        return $this->invoicePdfService->generate($invoice);
    }

    public function downloadPrescription(Request $request, int $prescriptionId): Response
    {
        $prescription = Prescription::findOrFail($prescriptionId);

        // Verify access
        $user = $request->user();
        $pet = $prescription->pet;
        if ($prescription->professional_id !== $user->id && $pet->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        return $this->prescriptionPdfService->generate($prescription);
    }

    public function downloadMedicalHistory(Request $request, int $petId): Response
    {
        $pet = Pet::where('id', $petId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return $this->medicalHistoryPdfService->generate($pet);
    }

    public function getRevenueReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $report = $this->revenueService->generateReport(
            $request->user(),
            \Carbon\Carbon::parse($validated['start_date']),
            \Carbon\Carbon::parse($validated['end_date'])
        );

        return response()->json(['data' => $report]);
    }
}

