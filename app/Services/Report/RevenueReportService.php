<?php

namespace App\Services\Report;

use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class RevenueReportService
{
    public function generateReport(
        User $professional,
        Carbon $startDate,
        Carbon $endDate
    ): array {
        $invoices = Invoice::where('professional_id', $professional->id)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->with(['client', 'items'])
            ->get();

        return [
            'period' => [
                'start' => $startDate->format('d/m/Y'),
                'end' => $endDate->format('d/m/Y'),
            ],
            'summary' => $this->calculateSummary($invoices),
            'by_service' => $this->groupByService($invoices),
            'by_month' => $this->groupByMonth($invoices),
            'invoices' => $invoices->map(fn($invoice) => [
                'invoice_number' => $invoice->invoice_number,
                'client' => $invoice->client->name,
                'date' => $invoice->paid_at->format('d/m/Y'),
                'amount' => $invoice->total_amount,
            ]),
        ];
    }

    private function calculateSummary(Collection $invoices): array
    {
        return [
            'total_revenue' => $invoices->sum('total_amount'),
            'total_invoices' => $invoices->count(),
            'average_ticket' => $invoices->avg('total_amount'),
            'unique_clients' => $invoices->pluck('client_id')->unique()->count(),
        ];
    }

    private function groupByService(Collection $invoices): array
    {
        $services = [];

        foreach ($invoices as $invoice) {
            foreach ($invoice->items as $item) {
                $serviceName = $item->description;
                
                if (!isset($services[$serviceName])) {
                    $services[$serviceName] = [
                        'name' => $serviceName,
                        'quantity' => 0,
                        'revenue' => 0,
                    ];
                }

                $services[$serviceName]['quantity'] += $item->quantity;
                $services[$serviceName]['revenue'] += $item->total;
            }
        }

        return array_values($services);
    }

    private function groupByMonth(Collection $invoices): array
    {
        return $invoices->groupBy(function ($invoice) {
            return $invoice->paid_at->format('Y-m');
        })->map(function ($monthInvoices, $month) {
            return [
                'month' => Carbon::parse($month)->format('m/Y'),
                'revenue' => $monthInvoices->sum('total_amount'),
                'invoices' => $monthInvoices->count(),
            ];
        })->values()->toArray();
    }
}

