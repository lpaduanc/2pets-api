<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Services\Invoice\InvoiceTotalsCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
    ) {}

    public function index(Request $request)
    {
        $query = Invoice::with(['client', 'professional', 'appointment'])
            ->where('professional_id', $request->user()->id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $invoices = $query->orderBy('issue_date', 'desc')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        return JsonResource::collection($invoices);
    }

    /**
     * `total` nunca vem do cliente: é sempre recalculado aqui a partir de `items` +
     * `discount`/`tax` (`InvoiceTotalsCalculator`). Um total divergente dos itens é uma
     * fatura financeiramente incorreta, e a única forma de garantir que isso nunca aconteça
     * é o servidor nunca aceitar o valor de fora.
     */
    public function store(StoreInvoiceRequest $request)
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
            'invoice_number' => 'INV-'.strtoupper(Str::random(8)),
        ]);

        return response()->json(['message' => 'Invoice created', 'invoice' => $invoice], 201);
    }

    public function show($id)
    {
        $invoice = Invoice::with(['client', 'professional', 'appointment'])
            ->where('professional_id', request()->user()->id)
            ->findOrFail($id);

        return response()->json($invoice);
    }

    /**
     * Quando `items` não é enviado, os itens e o `discount`/`tax` atuais da fatura são
     * mantidos e só o total é recalculado — nunca aceitamos um `total` avulso do cliente.
     */
    public function update(UpdateInvoiceRequest $request, $id)
    {
        $invoice = Invoice::where('professional_id', $request->user()->id)->findOrFail($id);
        $data = $request->validated();

        $totals = $this->totalsCalculator->recalculate(
            $data['items'] ?? $invoice->items,
            (float) ($data['discount'] ?? $invoice->discount),
            (float) ($data['tax'] ?? $invoice->tax),
        );

        $invoice->update([...$data, ...$totals]);

        return response()->json(['message' => 'Invoice updated', 'invoice' => $invoice]);
    }

    public function destroy(Request $request, $id)
    {
        $invoice = Invoice::where('professional_id', $request->user()->id)->findOrFail($id);
        $invoice->delete();

        return response()->json(['message' => 'Invoice removed']);
    }
}
