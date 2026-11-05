<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\CancelSaleReceiptRequest;
use App\Http\Resources\Commercial\SaleReceiptResource;
use App\Http\Resources\Commercial\SaleResource;
use App\Models\Sale;
use App\Services\Commercial\Authorization\SalePermissionGate;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\Reversal\ReceiptCancellationService;
use Illuminate\Http\JsonResponse;

/**
 * Estorno de UM recebimento da venda (docs/gap-petflow/PLANO.md, 2.3). `sales.cancel_receipt` é
 * julgada pelo cargo na organização da venda (`SalePermissionGate`), não pelo middleware.
 */
class SaleReceiptCancellationController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SalePermissionGate $permissions,
        private readonly ReceiptCancellationService $cancellation,
    ) {}

    public function __invoke(CancelSaleReceiptRequest $request, int $id, int $receiptId): JsonResponse
    {
        $sale = $this->scope->scopeQuery(Sale::query(), $request->user())->findOrFail($id);
        abort_unless($this->permissions->allows($request->user(), $sale, SalePermissionGate::CANCEL_RECEIPT), 403, 'Você não tem permissão para estornar recebimentos nesta venda.');
        $receipt = $sale->receipts()->with('paymentMethod')->findOrFail($receiptId);

        $updated = $this->cancellation->cancel($sale, $receipt, $request->user(), $request->string('reason')->toString());

        return (new SaleReceiptResource($receipt->fresh(['paymentMethod', 'receivables', 'cancelledBy:id,name'])))
            ->additional(['message' => 'Recebimento estornado.', 'sale' => new SaleResource($updated)])
            ->response();
    }
}
