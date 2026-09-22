<?php

namespace App\Services\Purchase;

use App\Enums\ProductPurpose;
use App\Enums\PurchaseInstallmentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\PricingService;
use App\Services\Finance\PurchaseFinancialEntryRecorder;
use App\Services\Stock\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compra (entrada de nota) — contrato docs/gap-simplesvet/06-compras-fornecedores-xml.md.
 *
 * Ciclo: rascunho (livre para editar — é a tela de conferência do XML) → `receive()` →
 * eventualmente `cancel()`. Só o `receive()` tem efeito fora da compra, e todo ele numa
 * transação:
 *
 *  - entrada de estoque (`StockService::in`, tipo `purchase_in`), que recalcula custo médio
 *    ponderado e último custo;
 *  - preço de venda aceito (`applied_sale_price`) gravado no produto, com o markup;
 *  - memória "código do fornecedor → produto" para a próxima importação;
 *  - parcelas a pagar a partir do plano de pagamento;
 *  - baixa do pedido de compra de origem, se houver.
 *
 * O cancelamento é o espelho: saída `return_out` desfazendo o custo médio, parcelas não pagas
 * canceladas (as pagas ficam — dinheiro que saiu não "volta" por cancelar a nota).
 */
final class PurchaseService
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly PricingService $pricing,
        private readonly StockService $stock,
        private readonly OwnerSequence $sequence,
        private readonly InstallmentPlanner $installments,
        private readonly NfeXmlImportService $xmlImport,
        private readonly PurchaseFinancialEntryRecorder $financialEntries,
    ) {}

    /**
     * @param  array<string, mixed>  $data  payload validado de `StorePurchaseRequest`
     */
    public function create(User $user, array $data): Purchase
    {
        return DB::transaction(function () use ($user, $data): Purchase {
            $ownership = $this->scope->ownershipFor($user);
            $supplier = $this->resolveSupplier($user, $data);
            $this->assertNotDuplicate($user, $data['invoice_key'] ?? null);

            $purchase = Purchase::create($this->headerAttributes($user, $data, $supplier) + [
                'code' => $this->sequence->next(Purchase::class, $ownership),
                'status' => PurchaseStatus::DRAFT,
                'created_by' => $user->id,
            ] + $ownership);

            $this->syncItems($purchase, $user, $data['items']);

            return $purchase->fresh(Purchase::RESOURCE_RELATIONS);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Purchase $purchase, User $user, array $data): Purchase
    {
        $this->assertDraft($purchase);

        return DB::transaction(function () use ($purchase, $user, $data): Purchase {
            $supplier = $this->resolveSupplier($user, $data);
            $this->assertNotDuplicate($user, $data['invoice_key'] ?? null, $purchase->id);

            $purchase->update($this->headerAttributes($user, $data, $supplier, $purchase));
            $this->syncItems($purchase, $user, $data['items']);

            return $purchase->fresh(Purchase::RESOURCE_RELATIONS);
        });
    }

    public function receive(Purchase $purchase, User $user): Purchase
    {
        $this->assertDraft($purchase);
        abort_if($purchase->items()->count() === 0, 422, 'Compra sem itens não pode ser efetivada.');

        return DB::transaction(function () use ($purchase, $user): Purchase {
            // Ordem fixa por produto: duas entradas simultâneas com os mesmos produtos travam
            // as linhas na mesma sequência e não entram em deadlock.
            $items = $purchase->items()->with('product')->orderBy('product_id')->get();

            foreach ($items as $item) {
                $this->receiveItem($purchase, $item, $user);
            }

            $this->createInstallments($purchase);

            $purchase->update([
                'status' => PurchaseStatus::RECEIVED,
                'received_at' => now(),
            ]);

            // Perna contábil da compra (doc 02): 1 `financial_entries` de despesa por parcela,
            // ligada por `purchase_installments.financial_entry_id`.
            $this->financialEntries->recordForReceivedPurchase($purchase, $user);

            if ($purchase->purchase_order_id !== null) {
                $this->refreshOrderStatus($purchase->purchaseOrder);
            }

            return $purchase->fresh(Purchase::RESOURCE_RELATIONS);
        });
    }

    public function cancel(Purchase $purchase, User $user, string $reason): Purchase
    {
        abort_if($purchase->status === PurchaseStatus::CANCELLED, 422, 'Compra já cancelada.');

        return DB::transaction(function () use ($purchase, $user, $reason): Purchase {
            if ($purchase->status === PurchaseStatus::RECEIVED) {
                foreach ($purchase->items()->with('product')->orderBy('product_id')->get() as $item) {
                    $this->reverseItem($purchase, $item, $user);
                }

                $purchase->installments()
                    ->where('status', PurchaseInstallmentStatus::PENDING->value)
                    ->update(['status' => PurchaseInstallmentStatus::CANCELLED->value]);

                $this->financialEntries->cancelForPurchase($purchase);
            }

            $purchase->update([
                'status' => PurchaseStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            if ($purchase->purchase_order_id !== null) {
                $this->refreshOrderStatus($purchase->purchaseOrder);
            }

            return $purchase->fresh(Purchase::RESOURCE_RELATIONS);
        });
    }

    public function delete(Purchase $purchase): void
    {
        $this->assertDraft($purchase);
        $purchase->delete();
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function receiveItem(Purchase $purchase, PurchaseItem $item, User $user): void
    {
        $product = $item->product;
        $unitCost = $item->effectiveUnitCost();

        $this->stock->in($product, StockMovementType::PURCHASE_IN, $item->quantity, [
            'unit_cost' => $unitCost,
            'batch_code' => $item->batch,
            'expires_at' => $item->expires_at?->toDateString(),
            'reference' => $purchase,
            'user' => $user,
            'occurred_at' => $purchase->entered_at,
            'notes' => 'Compra '.$purchase->code.($purchase->invoice_number ? ' — NF '.$purchase->invoice_number : ''),
        ]);

        $product->refresh();
        $changes = ['last_supplier_id' => $purchase->supplier_id];

        // Produto que NÃO controla estoque não passa pelo livro, mas o custo da compra
        // continua sendo o custo dele.
        if (! $product->controls_stock) {
            $changes['last_cost'] = $unitCost;
            $changes['average_cost'] = $unitCost;
        }

        if ($item->applied_sale_price !== null) {
            $price = (float) $item->applied_sale_price;
            $changes['price'] = $price;
            $changes['markup_percent'] = $this->pricing->markupFromCost($unitCost, $price);
        }

        if ($product->ncm === null && $item->ncm !== null) {
            $changes['ncm'] = $item->ncm;
        }

        // Validade do cadastro acompanha o lote mais próximo de vencer que entrou.
        if ($item->expires_at !== null && ($product->expiry_date === null || $item->expires_at->lt($product->expiry_date))) {
            $changes['expiry_date'] = $item->expires_at->toDateString();
        }

        $product->forceFill($changes)->save();

        if ($item->supplier_product_code !== null) {
            SupplierProduct::updateOrCreate(
                ['supplier_id' => $purchase->supplier_id, 'supplier_product_code' => $item->supplier_product_code],
                ['product_id' => $product->id],
            );
        }

        if ($item->purchase_order_item_id !== null) {
            PurchaseOrderItem::whereKey($item->purchase_order_item_id)->increment('received_quantity', $item->quantity);
        }
    }

    private function reverseItem(Purchase $purchase, PurchaseItem $item, User $user): void
    {
        $batchId = $item->batch === null
            ? null
            : ProductBatch::where('product_id', $item->product_id)->where('batch_code', $item->batch)->value('id');

        $this->stock->out($item->product, StockMovementType::RETURN_OUT, $item->quantity, [
            'unit_cost' => $item->effectiveUnitCost(),
            'batch_id' => $batchId,
            'reverse_average_cost' => true,
            'reference' => $purchase,
            'user' => $user,
            'notes' => 'Cancelamento da compra '.$purchase->code,
        ]);

        if ($item->purchase_order_item_id !== null) {
            PurchaseOrderItem::whereKey($item->purchase_order_item_id)
                ->where('received_quantity', '>=', $item->quantity)
                ->decrement('received_quantity', $item->quantity);
        }
    }

    private function createInstallments(Purchase $purchase): void
    {
        if ($purchase->installments_count === null || $purchase->installments_count < 1 || (float) $purchase->total <= 0) {
            return;
        }

        $first = $purchase->first_due_date
            ? CarbonImmutable::parse($purchase->first_due_date)
            : CarbonImmutable::parse($purchase->entered_at)->addDays($purchase->installment_interval_days);

        $plan = $this->installments->plan(
            (float) $purchase->total,
            $purchase->installments_count,
            $first,
            $purchase->installment_interval_days,
            $purchase->organization_id,
        );

        foreach ($plan as $row) {
            $purchase->installments()->create($row + [
                'payment_method_id' => $purchase->payment_method_id,
                'financial_account_id' => $purchase->financial_account_id,
                'status' => PurchaseInstallmentStatus::PENDING,
            ]);
        }
    }

    public function refreshOrderStatus(PurchaseOrder $order): void
    {
        if ($order->status === PurchaseOrderStatus::CANCELLED) {
            return;
        }

        $items = $order->items()->get();
        $received = $items->sum('received_quantity');

        $status = match (true) {
            $received === 0 => $order->sent_at !== null ? PurchaseOrderStatus::SENT : PurchaseOrderStatus::DRAFT,
            $items->every(fn (PurchaseOrderItem $i) => $i->received_quantity >= $i->quantity) => PurchaseOrderStatus::RECEIVED,
            default => PurchaseOrderStatus::PARTIALLY_RECEIVED,
        };

        $order->update(['status' => $status]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerAttributes(User $user, array $data, Supplier $supplier, ?Purchase $current = null): array
    {
        $payment = $data['payment'] ?? null;

        $attributes = [
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $this->resolveOrderId($user, $data['purchase_order_id'] ?? null, $supplier),
            'invoice_number' => $data['invoice_number'] ?? null,
            'invoice_series' => $data['invoice_series'] ?? null,
            'invoice_key' => $data['invoice_key'] ?? null,
            'invoice_issued_at' => $data['invoice_issued_at'] ?? null,
            'entered_at' => $data['entered_at'] ?? $current?->entered_at ?? now(),
            'total_freight' => round((float) ($data['total_freight'] ?? 0), 2),
            'total_discount' => round((float) ($data['total_discount'] ?? 0), 2),
            'notes' => $data['notes'] ?? null,
            'payment_method_id' => $this->scopedId($user, PaymentMethod::class, $payment['payment_method_id'] ?? null),
            'financial_account_id' => $this->scopedId($user, FinancialAccount::class, $payment['financial_account_id'] ?? null),
            'installments_count' => $payment === null ? null : (int) ($payment['installments'] ?? 1),
            'first_due_date' => $payment['first_due_date'] ?? null,
            'installment_interval_days' => (int) ($payment['interval_days'] ?? 30),
        ];

        if (! empty($data['xml_token'])) {
            $path = $this->xmlImport->resolveToken($user, (string) $data['xml_token']);
            abort_if($path === null, 422, 'Arquivo XML expirado ou inválido. Importe a nota novamente.');
            $attributes['xml_path'] = $path;
        }

        return $attributes;
    }

    /**
     * Substitui a lista de itens do rascunho. Produto novo (`new_product`) é cadastrado aqui,
     * com estoque zero — a entrada só acontece no `receive()`.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function syncItems(Purchase $purchase, User $user, array $items): void
    {
        $purchase->items()->delete();

        foreach ($items as $index => $data) {
            $product = $this->resolveProduct($user, $data, $index);
            $quantity = (int) $data['quantity'];
            $unitCost = round((float) $data['unit_cost'], 4);
            $discount = round((float) ($data['discount'] ?? 0), 2);
            $markup = isset($data['markup_percent']) ? (float) $data['markup_percent'] : null;
            $applied = isset($data['applied_sale_price']) ? round((float) $data['applied_sale_price'], 2) : null;

            if ($markup === null && $applied !== null) {
                $markup = $this->pricing->markupFromCost($unitCost, $applied);
            }

            $purchase->items()->create([
                'product_id' => $product->id,
                'purchase_order_item_id' => $this->resolveOrderItemId($purchase, $data['purchase_order_item_id'] ?? null),
                'supplier_product_code' => $data['supplier_product_code'] ?? null,
                'description_on_invoice' => $data['description_on_invoice'] ?? null,
                'quantity' => $quantity,
                'unit' => $data['unit'] ?? $product->unit_of_sale,
                'unit_cost' => $unitCost,
                'discount' => $discount,
                'total_cost' => round(max(0, $quantity * $unitCost - $discount), 2),
                'markup_percent' => $markup,
                'suggested_price' => $markup === null ? null : $this->pricing->priceFromMarkup($unitCost, $markup),
                'applied_sale_price' => $applied,
                'batch' => $data['batch'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'ncm' => $data['ncm'] ?? $product->ncm,
                'purpose' => $data['purpose'] ?? $product->purpose?->value ?? ProductPurpose::RESALE->value,
            ]);
        }

        $purchase->recalculateTotals();
        $purchase->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveProduct(User $user, array $data, int $index): Product
    {
        if (! empty($data['product_id'])) {
            return $this->scope->scopeQuery(Product::query(), $user)->findOrFail((int) $data['product_id']);
        }

        $new = $data['new_product'] ?? null;
        abort_if(! is_array($new), 422, sprintf('Item %d: vincule a um produto existente ou cadastre um novo.', $index + 1));

        $unitCost = round((float) $data['unit_cost'], 4);
        $attributes = $new + [
            'average_cost' => 0,
            'last_cost' => $unitCost,
            'unit_of_sale' => $data['unit'] ?? 'UN',
            'ncm' => $data['ncm'] ?? null,
            'purpose' => $data['purpose'] ?? ProductPurpose::RESALE->value,
        ];
        $attributes['sku'] ??= $new['gtin'] ?? ('CMP-'.Str::upper(Str::random(8)));
        $attributes['price'] ??= $data['applied_sale_price'] ?? null;
        $attributes['markup_percent'] ??= $data['markup_percent'] ?? null;
        // Item que já chega com lote na nota nasce controlando lote — senão o lote informado
        // na entrada se perderia no primeiro recebimento.
        $attributes['track_batches'] ??= ! empty($data['batch']);

        // Sem custo médio ainda (estoque zero): o markup informado deriva o preço a partir do
        // custo DESTA compra.
        $priced = $this->pricing->resolvePricing(['average_cost' => $unitCost] + $attributes);
        $priced['average_cost'] = 0;
        $priced['price'] ??= 0;
        $priced['stock_quantity'] = 0;

        return Product::create($priced + $this->scope->ownershipFor($user));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSupplier(User $user, array $data): Supplier
    {
        if (! empty($data['supplier_id'])) {
            return $this->scope->scopeQuery(Supplier::query(), $user)->findOrFail((int) $data['supplier_id']);
        }

        $payload = $data['supplier'] ?? null;
        abort_if(! is_array($payload) || empty($payload['legal_name']), 422, 'Informe o fornecedor.');

        $document = isset($payload['document']) ? preg_replace('/\D/', '', (string) $payload['document']) : null;

        // Mesmo CNPJ já cadastrado: reaproveita em vez de duplicar (critério de aceite).
        if ($document) {
            $existing = $this->scope->scopeQuery(Supplier::query(), $user)->where('document', $document)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return Supplier::create(collect($payload)->only((new Supplier)->getFillable())->except(['organization_id', 'professional_id'])->all()
            + $this->scope->ownershipFor($user));
    }

    private function resolveOrderId(User $user, mixed $orderId, Supplier $supplier): ?int
    {
        if (empty($orderId)) {
            return null;
        }

        $order = $this->scope->scopeQuery(PurchaseOrder::query(), $user)->findOrFail((int) $orderId);
        abort_unless($order->status->isReceivable(), 422, 'Pedido de compra não aceita mais recebimento.');
        abort_if($order->supplier_id !== $supplier->id, 422, 'O pedido de compra é de outro fornecedor.');

        return $order->id;
    }

    private function resolveOrderItemId(Purchase $purchase, mixed $orderItemId): ?int
    {
        if (empty($orderItemId) || $purchase->purchase_order_id === null) {
            return null;
        }

        return PurchaseOrderItem::query()
            ->where('purchase_order_id', $purchase->purchase_order_id)
            ->whereKey((int) $orderItemId)
            ->value('id');
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function scopedId(User $user, string $model, mixed $id): ?int
    {
        if (empty($id)) {
            return null;
        }

        return $this->scope->scopeQuery($model::query(), $user)->findOrFail((int) $id)->getKey();
    }

    private function assertNotDuplicate(User $user, ?string $invoiceKey, ?int $ignoreId = null): void
    {
        $duplicate = $this->xmlImport->duplicateOf($invoiceKey, $user, $ignoreId);

        abort_if($duplicate !== null, 422, 'Esta NF-e já foi lançada (compra #'.$duplicate.').');
    }

    private function assertDraft(Purchase $purchase): void
    {
        abort_unless($purchase->isDraft(), 422, sprintf(
            'Compra %s não pode mais ser alterada (situação: %s).',
            $purchase->code,
            mb_strtolower($purchase->status->label())
        ));
    }
}
