<?php

namespace App\Services\Report;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

final class InvoicePdfService
{
    public function generate(Invoice $invoice): Response
    {
        $pdf = Pdf::loadView('pdfs.invoice', [
            'invoice' => $invoice->load([
                'professional',
                'client',
                'items',
            ]),
        ]);

        $filename = "invoice-{$invoice->invoice_number}.pdf";

        return $pdf->download($filename);
    }

    public function generateAndStore(Invoice $invoice): string
    {
        $pdf = Pdf::loadView('pdfs.invoice', [
            'invoice' => $invoice->load([
                'professional',
                'client',
                'items',
            ]),
        ]);

        $filename = "invoices/invoice-{$invoice->invoice_number}.pdf";
        $path = storage_path("app/public/{$filename}");

        $pdf->save($path);

        return $filename;
    }
}

