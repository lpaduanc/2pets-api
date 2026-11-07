<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\Purchase\Quotes\PurchaseQuotePresenter;
use App\Services\Purchase\Quotes\PurchaseQuotePublicService;
use App\Support\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Página pública do fornecedor na cotação (PLANO 3.5), sem login — mesmo padrão de
 * `QuoteDecisionController`: 404 para token inválido, 410 para encerrada/vencida/já
 * respondida, payload em lista branca (`PurchaseQuotePresenter::publicView`), rate limit na rota.
 */
class PurchaseQuoteResponseController extends Controller
{
    public function __construct(
        private readonly PurchaseQuotePublicService $public,
        private readonly PurchaseQuotePresenter $presenter,
    ) {}

    public function show(string $token): JsonResponse
    {
        return response()->json(['data' => $this->presenter->publicView($this->public->view($token))]);
    }

    public function respond(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'freight' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'delivery_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:'.BusinessClock::todayString()],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.quote_item_id' => ['required', 'integer', 'distinct'],
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999'],
        ]);

        return response()->json([
            'data' => $this->presenter->publicView($this->public->respond($token, $data, $request->ip())),
            'message' => 'Cotação enviada. Obrigado!',
        ]);
    }

    public function reject(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return response()->json([
            'data' => $this->presenter->publicView($this->public->reject($token, $data['reason'] ?? null)),
            'message' => 'Recusa registrada. A clínica foi avisada.',
        ]);
    }
}
