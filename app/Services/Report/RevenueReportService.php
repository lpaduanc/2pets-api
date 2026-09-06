<?php

namespace App\Services\Report;

use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * All aggregates below query the real `invoices` schema directly instead of
 * loading every paid invoice into PHP: the previous version filtered on
 * `paid_at`/`total_amount` and an `items()` relation that don't exist on this
 * table (the real columns are `payment_date`, `total`, and a `json` `items`
 * column with one row per invoice) — every call threw a QueryException. Fixed
 * while pushing the aggregation into SQL, per the same rule ("nunca `->get()`
 * seguido de `->sum()`").
 */
final class RevenueReportService
{
    public function generateReport(
        User $professional,
        Carbon $startDate,
        Carbon $endDate
    ): array {
        $professionalId = $professional->id;

        return [
            'period' => [
                'start' => $startDate->format('d/m/Y'),
                'end' => $endDate->format('d/m/Y'),
            ],
            'summary' => $this->fetchSummary($professionalId, $startDate, $endDate),
            'by_service' => $this->fetchByService($professionalId, $startDate, $endDate),
            'by_month' => $this->fetchByMonth($professionalId, $startDate, $endDate),
            'invoices' => $this->fetchInvoiceList($professionalId, $startDate, $endDate),
        ];
    }

    private function fetchSummary(int $professionalId, Carbon $startDate, Carbon $endDate): array
    {
        $row = $this->paidInvoicesQuery($professionalId, $startDate, $endDate)
            ->selectRaw(
                'COALESCE(SUM(total), 0) AS total_revenue,
                 COUNT(*) AS total_invoices,
                 COALESCE(AVG(total), 0) AS average_ticket,
                 COUNT(DISTINCT client_id) AS unique_clients'
            )
            ->first();

        return [
            'total_revenue' => (float) $row->total_revenue,
            'total_invoices' => (int) $row->total_invoices,
            'average_ticket' => (float) $row->average_ticket,
            'unique_clients' => (int) $row->unique_clients,
        ];
    }

    /**
     * `items` is a `json` array per invoice (`[{service_id, description,
     * quantity, price}]`), not a related table — `json_array_elements`
     * unnests it so the grouping happens in SQL.
     */
    private function fetchByService(int $professionalId, Carbon $startDate, Carbon $endDate): array
    {
        $rows = $this->paidInvoicesQuery($professionalId, $startDate, $endDate)
            ->crossJoin(DB::raw('json_array_elements(items) AS item'))
            ->selectRaw(
                "item->>'description' AS name,
                 COALESCE(SUM(NULLIF(item->>'quantity', '')::numeric), 0) AS quantity,
                 COALESCE(SUM(NULLIF(item->>'quantity', '')::numeric * NULLIF(item->>'price', '')::numeric), 0) AS revenue"
            )
            ->groupBy(DB::raw("item->>'description'"))
            ->orderByDesc('revenue')
            ->get();

        return $rows->map(fn ($row): array => [
            'name' => $row->name,
            'quantity' => (float) $row->quantity,
            'revenue' => (float) $row->revenue,
        ])->all();
    }

    private function fetchByMonth(int $professionalId, Carbon $startDate, Carbon $endDate): array
    {
        $rows = $this->paidInvoicesQuery($professionalId, $startDate, $endDate)
            ->selectRaw(
                "date_trunc('month', payment_date) AS month,
                 COALESCE(SUM(total), 0) AS revenue,
                 COUNT(*) AS invoices"
            )
            ->groupBy(DB::raw("date_trunc('month', payment_date)"))
            ->orderBy('month')
            ->get();

        return $rows->map(fn ($row): array => [
            'month' => Carbon::parse($row->month)->format('m/Y'),
            'revenue' => (float) $row->revenue,
            'invoices' => (int) $row->invoices,
        ])->all();
    }

    private function fetchInvoiceList(int $professionalId, Carbon $startDate, Carbon $endDate): array
    {
        $invoices = $this->paidInvoicesQuery($professionalId, $startDate, $endDate)
            ->with('client:id,name')
            ->orderBy('payment_date')
            ->get(['id', 'invoice_number', 'client_id', 'payment_date', 'total']);

        return $invoices->map(fn (Invoice $invoice): array => [
            'invoice_number' => $invoice->invoice_number,
            'client' => $invoice->client->name,
            'date' => $invoice->payment_date->format('d/m/Y'),
            'amount' => (float) $invoice->total,
        ])->all();
    }

    private function paidInvoicesQuery(int $professionalId, Carbon $startDate, Carbon $endDate): Builder
    {
        return Invoice::query()
            ->where('professional_id', $professionalId)
            ->where('status', 'paid')
            ->whereBetween('payment_date', [$startDate->toDateString(), $endDate->toDateString()]);
    }
}
