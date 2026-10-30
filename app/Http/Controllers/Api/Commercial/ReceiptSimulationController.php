<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\CardBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\SimulateReceiptFeeRequest;
use App\Models\PaymentMethod;
use App\Services\Commercial\Card\ReceiptPricing;
use App\Services\Commercial\CommercialScopeResolver;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * "Você recebe R$ X em N parcelas (taxa R$ Y)" no PDV — o mesmo `ReceiptPricing` que grava o
 * recebimento, para a prévia e o gravado nunca divergirem (docs/gap-petflow 1.1).
 */
class ReceiptSimulationController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly ReceiptPricing $pricing,
    ) {}

    public function __invoke(SimulateReceiptFeeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $method = $this->scope->scopeQuery(PaymentMethod::query(), $request->user())
            ->with('feeTiers')
            ->findOrFail((int) $data['payment_method_id']);

        $quote = $this->pricing->quote(
            $method,
            Money::of($data['amount']),
            (int) ($data['installments'] ?? 1),
            isset($data['card_brand']) ? CardBrand::from($data['card_brand']) : null,
            isset($data['received_at']) ? CarbonImmutable::parse($data['received_at']) : CarbonImmutable::now(),
            $this->scope->ownershipFor($request->user())['organization_id'] ?? null,
        );

        return response()->json(['data' => $quote->toArray()]);
    }
}
