<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Services\Finance\FinancialOverviewService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tela "Financeiro" do profissional: faturas de atendimento E vendas do PDV numa leitura só
 * (docs/gap-simplesvet/01-caixa-pdv.md — o PDV tem que refletir no financeiro). Regras em
 * `FinancialOverviewService`.
 */
class FinancialOverviewController extends Controller
{
    use PaginatesResults;

    private const DEFAULT_PER_PAGE = 50;

    public function __construct(private readonly FinancialOverviewService $overview) {}

    /** Sem período, o mês corrente — é o "Receita do mês" da tela. */
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $request->filled('from') ? CarbonImmutable::parse($request->input('from')) : CarbonImmutable::now()->startOfMonth();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->input('to')) : CarbonImmutable::now()->endOfMonth();

        return response()->json(['data' => $this->overview->summary($request->user(), $from, $to)]);
    }

    public function entries(Request $request): JsonResponse
    {
        $request->validate([
            'source' => ['nullable', 'in:invoice,sale'],
            'status' => ['nullable', 'in:paid,pending,overdue,cancelled'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $this->overview->entries(
            $request->user(),
            [
                'source' => $request->input('source'),
                'status' => $request->input('status'),
                'from' => $request->filled('from') ? CarbonImmutable::parse($request->input('from')) : null,
                'to' => $request->filled('to') ? CarbonImmutable::parse($request->input('to')) : null,
                'search' => $request->filled('search') ? $request->string('search')->trim()->toString() : null,
            ],
            max(1, $request->integer('page', 1)),
            $this->resolvePerPage($request, self::DEFAULT_PER_PAGE),
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
