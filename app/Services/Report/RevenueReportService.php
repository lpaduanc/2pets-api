<?php

namespace App\Services\Report;

use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReceipt;
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
 *
 * Receita = faturas de atendimento pagas + o que entrou nas vendas do PDV (produto ou
 * serviço) — docs/gap-simplesvet/01-caixa-pdv.md: o PDV reflete no financeiro. Venda entra
 * pelo que foi RECEBIDO no período (`sale_receipts.received_at`), venda cancelada fica de fora.
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

        $salesRevenue = round((float) $this->receiptsQuery($professionalId, $startDate, $endDate)->sum('amount'), 2);
        $sales = $this->receiptsQuery($professionalId, $startDate, $endDate)
            ->join('sales', 'sales.id', '=', 'sale_receipts.sale_id')
            ->selectRaw('COUNT(DISTINCT sales.id) AS sales, COUNT(DISTINCT sales.client_id) AS clients')
            ->first();

        $totalRevenue = round((float) $row->total_revenue + $salesRevenue, 2);
        $documents = (int) $row->total_invoices + (int) $sales->sales;

        return [
            'total_revenue' => $totalRevenue,
            'total_invoices' => (int) $row->total_invoices,
            'invoices_revenue' => (float) $row->total_revenue,
            'total_sales' => (int) $sales->sales,
            'sales_revenue' => $salesRevenue,
            'average_ticket' => $documents > 0 ? round($totalRevenue / $documents, 2) : 0.0,
            // Aproximação: soma os clientes distintos de cada fonte (o mesmo tutor com fatura
            // e venda no período conta duas vezes). Venda sem cliente identificado não conta.
            'unique_clients' => (int) $row->unique_clients + (int) $sales->clients,
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

        $byName = $rows->mapWithKeys(fn ($row): array => [$row->name => [
            'name' => $row->name,
            'quantity' => (float) $row->quantity,
            'revenue' => (float) $row->revenue,
        ]])->all();

        // Itens das vendas do PDV pagas no período (produto e serviço), somados pelo nome.
        $saleItems = SaleItem::query()
            ->whereIn('sale_id', $this->paidSalesQuery($professionalId, $startDate, $endDate)->select('id'))
            ->selectRaw('description AS name, SUM(quantity) AS quantity, SUM(total) AS revenue')
            ->groupBy('description')
            ->get();

        foreach ($saleItems as $item) {
            $current = $byName[$item->name] ?? ['name' => $item->name, 'quantity' => 0.0, 'revenue' => 0.0];
            $current['quantity'] += (float) $item->quantity;
            $current['revenue'] += (float) $item->revenue;
            $byName[$item->name] = $current;
        }

        return collect($byName)->sortByDesc('revenue')->values()->all();
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

        $salesByMonth = $this->receiptsQuery($professionalId, $startDate, $endDate)
            ->selectRaw("to_char(date_trunc('month', received_at), 'MM/YYYY') AS month, SUM(amount) AS revenue")
            ->groupBy(DB::raw("date_trunc('month', received_at)"))
            ->pluck('revenue', 'month');

        $months = $rows->mapWithKeys(fn ($row): array => [Carbon::parse($row->month)->format('m/Y') => [
            'month' => Carbon::parse($row->month)->format('m/Y'),
            'revenue' => (float) $row->revenue,
            'invoices' => (int) $row->invoices,
        ]])->all();

        foreach ($salesByMonth as $month => $revenue) {
            $months[$month] ??= ['month' => $month, 'revenue' => 0.0, 'invoices' => 0];
            $months[$month]['revenue'] = round($months[$month]['revenue'] + (float) $revenue, 2);
        }

        return collect($months)
            ->sortBy(fn (array $row): string => Carbon::createFromFormat('m/Y', $row['month'])->format('Y-m'))
            ->values()
            ->all();
    }

    private function fetchInvoiceList(int $professionalId, Carbon $startDate, Carbon $endDate): array
    {
        $invoices = $this->paidInvoicesQuery($professionalId, $startDate, $endDate)
            ->with('client:id,name')
            ->orderBy('payment_date')
            ->get(['id', 'invoice_number', 'client_id', 'payment_date', 'total']);

        $rows = $invoices->map(fn (Invoice $invoice): array => [
            'invoice_number' => $invoice->invoice_number,
            'client' => $invoice->client->name,
            'date' => $invoice->payment_date->format('d/m/Y'),
            'amount' => (float) $invoice->total,
            'sort' => $invoice->payment_date->format('Y-m-d'),
        ]);

        // Cada recebimento de venda do PDV vira uma linha, na data em que o dinheiro entrou.
        $receipts = $this->receiptsQuery($professionalId, $startDate, $endDate)
            ->with('sale.client:id,name')
            ->get()
            ->map(fn (SaleReceipt $receipt): array => [
                'invoice_number' => 'Venda PDV '.($receipt->sale->number ?? $receipt->sale->id),
                'client' => $receipt->sale->client?->name ?? 'Consumidor não identificado',
                'date' => $receipt->received_at->format('d/m/Y'),
                'amount' => (float) $receipt->amount,
                'sort' => $receipt->received_at->format('Y-m-d'),
            ]);

        return $rows->concat($receipts)
            ->sortBy('sort')
            ->map(fn (array $row): array => collect($row)->except('sort')->all())
            ->values()
            ->all();
    }

    private function receiptsQuery(int $professionalId, Carbon $startDate, Carbon $endDate): Builder
    {
        return SaleReceipt::query()
            ->whereBetween('sale_receipts.received_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->whereHas('sale', fn (Builder $sale) => $sale
                ->where('professional_id', $professionalId)
                ->where('kind', SaleKind::SALE->value)
                ->where('status', '!=', SaleStatus::CANCELLED->value));
    }

    private function paidSalesQuery(int $professionalId, Carbon $startDate, Carbon $endDate): Builder
    {
        return Sale::query()
            ->where('professional_id', $professionalId)
            ->where('kind', SaleKind::SALE->value)
            ->where('status', SaleStatus::PAID->value)
            ->whereBetween('sold_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()]);
    }

    private function paidInvoicesQuery(int $professionalId, Carbon $startDate, Carbon $endDate): Builder
    {
        return Invoice::query()
            ->where('professional_id', $professionalId)
            ->where('status', 'paid')
            ->whereBetween('payment_date', [$startDate->toDateString(), $endDate->toDateString()]);
    }
}
