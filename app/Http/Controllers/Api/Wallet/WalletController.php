<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\WalletSummaryRequest;
use App\Services\Wallet\WalletCalendar;
use App\Services\Wallet\WalletCatalogService;
use App\Services\Wallet\WalletSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carteira do tutor: resumo de gastos do período e catálogo do formulário.
 *
 * Só o próprio tutor lê a própria carteira; não há leitura por terceiros (vet ou clínica).
 */
class WalletController extends Controller
{
    public function __construct(
        private readonly WalletSummaryService $summary,
        private readonly WalletCatalogService $catalog,
    ) {}

    public function index(WalletSummaryRequest $request): JsonResponse
    {
        [$from, $to] = $request->range(WalletCalendar::today());

        return response()->json([
            'data' => $this->summary->summarize($request->user(), $from, $to, $request->petId()),
        ]);
    }

    public function catalog(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->catalog->forUser($request->user())]);
    }
}
