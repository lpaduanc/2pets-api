<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\CancelInvoiceRequest;
use App\Http\Requests\Invoice\MarkInvoiceAsPaidRequest;
use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Http\Requests\Payment\RecordAdvancePaymentRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\Invoice\InvoiceLifecycleService;
use App\Services\Invoice\InvoiceTotalsCalculator;
use App\Services\Invoice\VisibleInvoicesQuery;
use App\Services\Payment\AdvancePaymentService;
use App\Services\Payment\PaymentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
    use PaginatesResults;

    /**
     * The financial screen derives its summary numbers from the rows it holds,
     * so the default page has to cover a professional's whole invoice list.
     */
    private const DEFAULT_PER_PAGE = 200;

    public function __construct(
        private readonly InvoiceTotalsCalculator $totalsCalculator,
        private readonly VisibleInvoicesQuery $visibleInvoicesQuery,
        private readonly InvoiceLifecycleService $lifecycleService,
        private readonly PaymentService $paymentService,
        private readonly AdvancePaymentService $advancePaymentService,
    ) {}

    /**
     * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §5/§11 item 3:
     * um colega da mesma organização também vê a fatura (front desk cobra), não só o autor —
     * `VisibleInvoicesQuery` já esconde rascunho de quem não é autor/dono da organização.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->visibleInvoicesQuery->forUser($request->user())
            ->with(['client', 'professional', 'appointment', 'payments']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $invoices = $query->orderBy('issue_date', 'desc')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        return InvoiceResource::collection($invoices);
    }

    /**
     * `total` nunca vem do cliente: é sempre recalculado aqui a partir de `items` +
     * `discount`/`tax` (`InvoiceTotalsCalculator`). Um total divergente dos itens é uma
     * fatura financeiramente incorreta, e a única forma de garantir que isso nunca aconteça
     * é o servidor nunca aceitar o valor de fora.
     */
    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $totals = $this->totalsCalculator->recalculate(
            $data['items'],
            (float) ($data['discount'] ?? 0),
            (float) ($data['tax'] ?? 0),
        );

        $invoice = Invoice::create([
            ...$data,
            ...$totals,
            'professional_id' => $request->user()->id,
            'organization_id' => $request->user()->activeOrganizationId(),
            'invoice_number' => 'INV-'.strtoupper(Str::random(8)),
        ]);

        return (new InvoiceResource($invoice))
            ->additional(['message' => 'Invoice created'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Também alcançável fora do prefixo `professional/` via `GET /invoices/{id}` — o mesmo
     * `client_id` da fatura (o tutor) pode ler, contrato §4 "Leitura (tutor)".
     * `InvoicePolicy::view` decide as duas audiências.
     */
    public function show(Request $request, int $id): InvoiceResource
    {
        $invoice = Invoice::with(['client', 'professional', 'appointment', 'payments'])->findOrFail($id);

        Gate::forUser($request->user())->authorize('view', $invoice);

        return new InvoiceResource($invoice);
    }

    /**
     * Quando `items` não é enviado, os itens e o `discount`/`tax` atuais da fatura são
     * mantidos e só o total é recalculado — nunca aceitamos um `total` avulso do cliente.
     *
     * Contrato §13.5/§13.8 invariante 13 (`InvoicePolicy::editWhilePending`): fatura de
     * atendimento é editável enquanto `pending` (itens congelam no pagamento, não na
     * emissão); fatura manual continua editável só em `draft` (invariante 8, inalterada
     * para esse caso).
     */
    public function update(UpdateInvoiceRequest $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        Gate::forUser($request->user())->authorize('editWhilePending', $invoice);

        $data = $request->validated();
        $totals = $this->totalsCalculator->recalculate(
            $data['items'] ?? $invoice->items,
            (float) ($data['discount'] ?? $invoice->discount),
            (float) ($data['tax'] ?? $invoice->tax),
        );

        $invoice->update([...$data, ...$totals]);

        return (new InvoiceResource($invoice))->additional(['message' => 'Invoice updated'])->response();
    }

    /**
     * Remoção definitiva do rascunho manual — continua restrita a `status = draft`
     * (`InvoicePolicy::editDraft`), mais estrita que `update`. Fatura de atendimento nunca
     * está em `draft` (§13.5), então nunca é apagável por aqui: descartá-la é `cancel()`
     * (auditável, com motivo), não um `DELETE` que a removeria sem rastro.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        Gate::forUser($request->user())->authorize('editDraft', $invoice);

        $invoice->delete();

        return response()->json(['message' => 'Invoice removed']);
    }

    /** POST professional/invoices/{id}/issue — draft → pending (contrato §4). */
    public function issue(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        Gate::forUser($request->user())->authorize('manage', $invoice);

        $invoice = $this->lifecycleService->issue($invoice);

        return (new InvoiceResource($invoice))
            ->additional(['message' => 'Fatura emitida com sucesso!'])
            ->response();
    }

    /** POST professional/invoices/{id}/cancel — draft|pending → cancelled (contrato §4). */
    public function cancel(CancelInvoiceRequest $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        Gate::forUser($request->user())->authorize('manage', $invoice);

        $invoice = $this->lifecycleService->cancel($invoice, $request->validated('reason'));

        return (new InvoiceResource($invoice))
            ->additional(['message' => 'Fatura cancelada.'])
            ->response();
    }

    /**
     * POST professional/invoices/{id}/mark-as-paid — pending → paid. Contrato §4/§6:
     * declaração auditável de quem recebeu, nunca autodeclarada pelo tutor
     * (`InvoicePolicy::receivePayment`).
     */
    public function markAsPaid(MarkInvoiceAsPaidRequest $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        Gate::forUser($request->user())->authorize('receivePayment', $invoice);

        $data = $request->validated();

        $invoice = $this->paymentService->markInvoiceAsPaidManually(
            $invoice,
            $request->user(),
            PaymentMethod::from($data['method']),
            isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : Carbon::now(),
            $data['notes'] ?? null,
            $data['credit_resolution'] ?? null,
        );

        return (new InvoiceResource($invoice->load('payments')))
            ->additional(['message' => 'Pagamento registrado com sucesso!'])
            ->response();
    }

    /**
     * POST professional/invoices/{id}/advance-payment — contrato docs/atendimento-veterinario/
     * 11-internacao-no-fluxo-de-faturamento.md §3-bis.4. Mesma autorização de `mark-as-paid`
     * (`InvoicePolicy::receivePayment` — adiantamento é receber dinheiro, não decidir o que
     * está sendo cobrado); a restrição a internação é checada no `AdvancePaymentService`, não
     * aqui.
     */
    public function recordAdvancePayment(RecordAdvancePaymentRequest $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        Gate::forUser($request->user())->authorize('receivePayment', $invoice);

        $data = $request->validated();

        $this->advancePaymentService->record(
            $invoice,
            $request->user(),
            PaymentMethod::from($data['method']),
            (float) $data['amount'],
            isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : Carbon::now(),
            $data['notes'] ?? null,
        );

        return (new InvoiceResource($invoice->fresh(['client', 'professional', 'appointment', 'payments'])))
            ->additional(['message' => 'Adiantamento registrado com sucesso!'])
            ->response();
    }
}
