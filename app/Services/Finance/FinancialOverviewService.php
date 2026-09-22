<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Invoice\VisibleInvoicesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * O "Financeiro" do profissional: TUDO que gera valor a receber ou recebido, venha de onde
 * vier — a fatura do atendimento (`invoices`) e a venda de balcão do PDV (`sales`, produto ou
 * serviço). Contrato docs/gap-simplesvet/01-caixa-pdv.md: o PDV tem que refletir no financeiro.
 *
 * Não é o livro-razão do doc 02 (plano de contas, lançamentos, DRE): é a leitura unificada das
 * duas fontes que JÁ existem, sem copiar dado. Quando o doc 02 entrar com `financial_entries`,
 * ele passa a ser a terceira perna aqui, e as telas não mudam.
 *
 * Regras de competência, iguais para as duas fontes:
 *  - RECEBIDO no período = dinheiro que entrou no período. Fatura: `paid` com `payment_date` no
 *    período (regra que a tela já usava). Venda: soma dos `sale_receipts.received_at` no
 *    período — recebimento parcial conta o que entrou, e venda cancelada não conta (o dinheiro
 *    foi estornado).
 *  - A RECEBER = saldo em aberto hoje. Fatura `pending` (vencida à parte); venda `kind = sale`
 *    ainda não paga nem cancelada, pelo `total − paid_amount`. Orçamento nunca entra: não é
 *    dívida de ninguém.
 */
final class FinancialOverviewService
{
    public function __construct(
        private readonly VisibleInvoicesQuery $invoices,
        private readonly CommercialScopeResolver $scope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $paidInvoices = $this->invoiceQuery($user)
            ->where('status', InvoiceStatus::PAID->value)
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()]);

        $invoiceReceived = round((float) (clone $paidInvoices)->sum('total'), 2);
        $invoiceReceivedCount = (clone $paidInvoices)->count();

        $receipts = $this->receiptsQuery($user, $from, $to)
            ->with(['sale.items', 'paymentMethod:id,name,kind'])
            ->get();

        $saleReceived = round((float) $receipts->sum('amount'), 2);
        $byItemType = $this->receivedByItemType($receipts);

        $pendingInvoices = $this->invoiceQuery($user)->where('status', InvoiceStatus::PENDING->value)->get(['id', 'total', 'due_date', 'status']);
        $overdueInvoices = $pendingInvoices->filter(fn (Invoice $invoice): bool => $invoice->isOverdue());
        $openInvoices = $pendingInvoices->reject(fn (Invoice $invoice): bool => $invoice->isOverdue());

        $openSales = $this->openSalesQuery($user)->get(['id', 'total', 'paid_amount']);
        $openSalesDue = round((float) $openSales->sum(fn (Sale $sale): float => $sale->amountDue()), 2);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'received' => [
                'total' => round($invoiceReceived + $saleReceived, 2),
                'count' => $invoiceReceivedCount + $receipts->pluck('sale_id')->unique()->count(),
                'invoices' => $invoiceReceived,
                'invoices_count' => $invoiceReceivedCount,
                'sales' => $saleReceived,
                'sales_count' => $receipts->pluck('sale_id')->unique()->count(),
                'sales_products' => $byItemType['product'],
                'sales_services' => $byItemType['service'],
                'by_payment_method' => $this->receivedByPaymentMethod($receipts, $paidInvoices),
            ],
            'pending' => [
                'total' => round((float) $openInvoices->sum('total') + $openSalesDue, 2),
                'count' => $openInvoices->count() + $openSales->count(),
                'invoices' => round((float) $openInvoices->sum('total'), 2),
                'invoices_count' => $openInvoices->count(),
                'sales' => $openSalesDue,
                'sales_count' => $openSales->count(),
            ],
            'overdue' => [
                'total' => round((float) $overdueInvoices->sum('total'), 2),
                'count' => $overdueInvoices->count(),
            ],
        ];
    }

    /**
     * Lista única de lançamentos (fatura + venda), mais recente primeiro. As duas fontes são
     * lidas já ordenadas e limitadas a `page × perPage` cada, e intercaladas aqui — o bastante
     * para montar qualquer página sem trazer o histórico inteiro.
     *
     * @param  array{source?: string|null, status?: string|null, from?: CarbonImmutable|null, to?: CarbonImmutable|null, search?: string|null}  $filters
     */
    public function entries(User $user, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $source = $filters['source'] ?? null;
        $window = $page * $perPage;

        $invoiceQuery = $source === 'sale' ? null : $this->filteredInvoices($user, $filters);
        $saleQuery = $source === 'invoice' ? null : $this->filteredSales($user, $filters);

        $total = ($invoiceQuery ? (clone $invoiceQuery)->count() : 0) + ($saleQuery ? (clone $saleQuery)->count() : 0);

        $rows = collect();

        if ($invoiceQuery !== null) {
            $rows = $rows->concat(
                $invoiceQuery->with(['client:id,name', 'appointment.pet:id,name'])
                    ->orderByDesc('issue_date')->orderByDesc('id')
                    ->limit($window)->get()
                    ->map(fn (Invoice $invoice): array => $this->invoiceRow($invoice))
            );
        }

        if ($saleQuery !== null) {
            $rows = $rows->concat(
                $saleQuery->with(['client:id,name', 'pet:id,name', 'items', 'receipts.paymentMethod:id,name'])
                    ->orderByRaw('COALESCE(sold_at, created_at) DESC')->orderByDesc('id')
                    ->limit($window)->get()
                    ->map(fn (Sale $sale): array => $this->saleRow($sale))
            );
        }

        $pageRows = $rows
            ->sortByDesc(fn (array $row): string => $row['date'].sprintf('%012d', $row['id']))
            ->values()
            ->slice(($page - 1) * $perPage, $perPage)
            ->values();

        return new LengthAwarePaginator($pageRows, $total, $perPage, $page);
    }

    /**
     * Recebido de venda por período — o que o dashboard e o relatório de receita somam às
     * faturas. `$professionalIds` segue o recorte do chamador (dashboard agrega a equipe do
     * dono; relatório é pessoal), pelo `sales.professional_id` = quem registrou a venda.
     *
     * @param  list<int>  $professionalIds
     */
    public function saleReceiptsTotal(array $professionalIds, \DateTimeInterface $from, \DateTimeInterface $to): float
    {
        return round((float) SaleReceipt::query()
            ->whereBetween('received_at', [$from, $to])
            ->whereHas('sale', fn (Builder $sale) => $sale
                ->whereIn('professional_id', $professionalIds)
                ->where('kind', SaleKind::SALE->value)
                ->where('status', '!=', SaleStatus::CANCELLED->value))
            ->sum('amount'), 2);
    }

    // ------------------------------------------------------------------

    private function invoiceQuery(User $user): Builder
    {
        return $this->invoices->forUser($user);
    }

    private function receiptsQuery(User $user, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return SaleReceipt::query()
            ->whereBetween('received_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereHas('sale', fn (Builder $sale) => $this->scope->scopeQuery($sale, $user)
                ->where('kind', SaleKind::SALE->value)
                ->where('status', '!=', SaleStatus::CANCELLED->value));
    }

    private function openSalesQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(Sale::query(), $user)
            ->where('kind', SaleKind::SALE->value)
            ->whereIn('status', [SaleStatus::OPEN->value, SaleStatus::IN_SERVICE->value, SaleStatus::UNPAID->value])
            ->whereColumn('total', '>', 'paid_amount');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredInvoices(User $user, array $filters): Builder
    {
        $status = $filters['status'] ?? null;

        return $this->invoiceQuery($user)
            ->where('status', '!=', InvoiceStatus::DRAFT->value)
            ->when($status === 'paid', fn (Builder $q) => $q->where('status', InvoiceStatus::PAID->value))
            ->when($status === 'cancelled', fn (Builder $q) => $q->where('status', InvoiceStatus::CANCELLED->value))
            ->when($status === 'pending', fn (Builder $q) => $q->where('status', InvoiceStatus::PENDING->value)
                ->where(fn (Builder $due) => $due->whereNull('due_date')->orWhereDate('due_date', '>=', now()->toDateString())))
            ->when($status === 'overdue', fn (Builder $q) => $q->where('status', InvoiceStatus::PENDING->value)
                ->whereDate('due_date', '<', now()->toDateString()))
            ->when($filters['from'] ?? null, fn (Builder $q, CarbonImmutable $from) => $q->whereDate('issue_date', '>=', $from->toDateString()))
            ->when($filters['to'] ?? null, fn (Builder $q, CarbonImmutable $to) => $q->whereDate('issue_date', '<=', $to->toDateString()))
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $inner) => $inner
                ->where('invoice_number', 'ilike', "%{$term}%")
                ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'ilike', "%{$term}%"))));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredSales(User $user, array $filters): Builder
    {
        $status = $filters['status'] ?? null;
        $date = 'COALESCE(sales.sold_at, sales.created_at)';

        return $this->scope->scopeQuery(Sale::query(), $user)
            ->where('kind', SaleKind::SALE->value)
            // Venda sem item é rascunho de balcão abandonado, não valor lançado.
            ->where('total', '>', 0)
            ->when($status === 'paid', fn (Builder $q) => $q->where('status', SaleStatus::PAID->value))
            ->when($status === 'cancelled', fn (Builder $q) => $q->where('status', SaleStatus::CANCELLED->value))
            ->when($status === 'pending', fn (Builder $q) => $q->whereIn('status', [SaleStatus::OPEN->value, SaleStatus::IN_SERVICE->value, SaleStatus::UNPAID->value]))
            // Venda de balcão não tem vencimento: nunca está "vencida".
            ->when($status === 'overdue', fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->when($filters['from'] ?? null, fn (Builder $q, CarbonImmutable $from) => $q->whereRaw("{$date} >= ?", [$from->startOfDay()]))
            ->when($filters['to'] ?? null, fn (Builder $q, CarbonImmutable $to) => $q->whereRaw("{$date} <= ?", [$to->endOfDay()]))
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $inner) => $inner
                ->when(ctype_digit($term), fn (Builder $n) => $n->where('number', (int) $term))
                ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'ilike', "%{$term}%"))));
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRow(Invoice $invoice): array
    {
        $status = $invoice->isOverdue() ? 'overdue' : (string) $invoice->status;

        return [
            'source' => 'invoice',
            'id' => $invoice->id,
            'number' => $invoice->invoice_number,
            'date' => $invoice->issue_date?->toDateString() ?? $invoice->created_at->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'paid_at' => $invoice->payment_date?->toDateString(),
            'client' => $invoice->client ? ['id' => $invoice->client->id, 'name' => $invoice->client->name] : null,
            'pet' => $invoice->appointment?->pet ? ['id' => $invoice->appointment->pet->id, 'name' => $invoice->appointment->pet->name] : null,
            'description' => 'Atendimento',
            'total' => (float) $invoice->total,
            'paid_amount' => $status === 'paid' ? (float) $invoice->total : $invoice->amountPaid(),
            'amount_due' => $status === 'paid' || $status === 'cancelled' ? 0.0 : max(0.0, round((float) $invoice->total - $invoice->amountPaid(), 2)),
            'status' => $status,
            'products_total' => 0.0,
            'services_total' => (float) $invoice->total,
            'payment_methods' => array_values(array_filter([$invoice->payment_method])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saleRow(Sale $sale): array
    {
        $byType = $this->saleTotalsByItemType($sale);

        return [
            'source' => 'sale',
            'id' => $sale->id,
            'number' => (string) ($sale->number ?? $sale->id),
            'date' => ($sale->sold_at ?? $sale->created_at)->toDateString(),
            'due_date' => null,
            'paid_at' => $sale->status === SaleStatus::PAID ? $sale->receipts->max('received_at')?->toDateString() : null,
            'client' => $sale->client ? ['id' => $sale->client->id, 'name' => $sale->client->name] : null,
            'pet' => $sale->pet ? ['id' => $sale->pet->id, 'name' => $sale->pet->name] : null,
            'description' => $this->saleDescription($byType),
            'total' => (float) $sale->total,
            'paid_amount' => (float) $sale->paid_amount,
            'amount_due' => $sale->status === SaleStatus::CANCELLED ? 0.0 : max(0.0, $sale->amountDue()),
            'status' => match ($sale->status) {
                SaleStatus::PAID => 'paid',
                SaleStatus::CANCELLED => 'cancelled',
                default => 'pending',
            },
            'products_total' => $byType['product'],
            'services_total' => $byType['service'],
            'payment_methods' => $sale->receipts->pluck('paymentMethod.name')->filter()->unique()->values()->all(),
        ];
    }

    /** @param  array{product: float, service: float}  $byType */
    private function saleDescription(array $byType): string
    {
        return match (true) {
            $byType['product'] > 0 && $byType['service'] > 0 => 'Venda PDV — produtos e serviços',
            $byType['product'] > 0 => 'Venda PDV — produtos',
            default => 'Venda PDV — serviços',
        };
    }

    /**
     * Total da venda dividido entre produto e serviço, com o desconto da VENDA rateado pelo peso
     * de cada linha — senão a soma das duas partes passaria do total cobrado.
     *
     * Público (não só uso interno) porque `SaleFinancialEntryRecorder` (doc 02) reaproveita
     * exatamente este rateio para gerar o lançamento de receita — duas contas de desconto
     * divergentes para a mesma venda seria pior que o acoplamento.
     *
     * @return array{product: float, service: float}
     */
    public function saleTotalsByItemType(Sale $sale): array
    {
        $subtotal = (float) $sale->subtotal;
        $factor = $subtotal > 0 ? (float) $sale->total / $subtotal : 0.0;

        $product = (float) $sale->items->where('sellable_type', Product::class)->sum('total') * $factor;
        $service = (float) $sale->items->where('sellable_type', '!=', Product::class)->sum('total') * $factor;

        return ['product' => round($product, 2), 'service' => round($service, 2)];
    }

    /**
     * Recebido no período dividido entre produto e serviço, proporcional ao peso de cada tipo
     * na venda de cada recebimento.
     *
     * @param  Collection<int, SaleReceipt>  $receipts
     * @return array{product: float, service: float}
     */
    private function receivedByItemType(Collection $receipts): array
    {
        $product = 0.0;
        $service = 0.0;

        foreach ($receipts as $receipt) {
            $sale = $receipt->sale;
            $total = (float) $sale->total;

            if ($total <= 0) {
                continue;
            }

            $byType = $this->saleTotalsByItemType($sale);
            $product += (float) $receipt->amount * ($byType['product'] / $total);
            $service += (float) $receipt->amount * ($byType['service'] / $total);
        }

        return ['product' => round($product, 2), 'service' => round($service, 2)];
    }

    /**
     * @param  Collection<int, SaleReceipt>  $receipts
     * @return list<array{label: string, amount: float}>
     */
    private function receivedByPaymentMethod(Collection $receipts, Builder $paidInvoices): array
    {
        $totals = [];

        foreach ($receipts as $receipt) {
            $label = $receipt->paymentMethod?->name ?? 'Outros';
            $totals[$label] = ($totals[$label] ?? 0) + (float) $receipt->amount;
        }

        foreach ((clone $paidInvoices)->selectRaw('payment_method, SUM(total) AS amount')->groupBy('payment_method')->get() as $row) {
            $label = \App\Enums\PaymentMethod::tryFrom((string) $row->payment_method)?->label() ?? 'Outros';
            $totals[$label] = ($totals[$label] ?? 0) + (float) $row->amount;
        }

        arsort($totals);

        return array_map(
            fn (string $label, float $amount): array => ['label' => $label, 'amount' => round($amount, 2)],
            array_keys($totals),
            array_values($totals),
        );
    }
}
