<?php

namespace App\Http\Controllers\Api\Insights;

use App\DataTransferObjects\InsightQuery;
use App\Enums\InsightIndicator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Insights\InsightDrillDownRequest;
use App\Http\Requests\Insights\InsightQueryRequest;
use App\Models\User;
use App\Services\Insights\InsightExportService;
use App\Services\Insights\InsightsDrillDownService;
use App\Services\Insights\ProductivityAuthorization;
use App\Services\Insights\SalesInsightsService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET insights/{indicator}` (+ `drill-down`/`export`) — contrato
 * docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md.
 *
 * `InsightIndicator` é um conjunto FECHADO (decisão de arquitetura da spec): hoje só `sales`
 * existe, então o `match` abaixo tem um braço só — um indicador novo é um `case` no enum mais
 * um braço aqui, nunca um parâmetro livre resolvendo classe por nome.
 */
class InsightsController extends Controller
{
    public function __construct(
        private readonly SalesInsightsService $salesInsights,
        private readonly InsightsDrillDownService $drillDown,
        private readonly InsightExportService $export,
        private readonly ProductivityAuthorization $authorization,
    ) {}

    public function show(InsightQueryRequest $request, InsightIndicator $indicator): JsonResponse
    {
        $query = $request->toInsightQuery($this->authorization);
        $result = $this->seriesFor($indicator, $request->user(), $query);

        return response()->json($result + ['meta' => $this->meta($indicator, $request)]);
    }

    public function drillDown(InsightDrillDownRequest $request, InsightIndicator $indicator): JsonResponse
    {
        $result = $this->drillDown->paginate(
            $request->user(),
            $request->toInsightQuery($this->authorization),
            $request->bucket(),
            $request->page(),
            $request->perPage(),
        );

        return response()->json($result);
    }

    public function export(InsightDrillDownRequest $request, InsightIndicator $indicator): StreamedResponse
    {
        $csv = $this->export->toCsv($request->user(), $request->toInsightQuery($this->authorization), $request->bucket());
        $filename = "{$indicator->value}-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(
            function () use ($csv): void {
                // BOM UTF-8: mesma prática de `PriceListController::export()` (doc 07) — sem
                // isto o Excel no Windows abre acento como sequência quebrada.
                echo "\xEF\xBB\xBF".$csv;
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * @return array{series: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function seriesFor(InsightIndicator $indicator, User $user, InsightQuery $query): array
    {
        return match ($indicator) {
            InsightIndicator::SALES => $this->salesInsights->series($user, $query),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(InsightIndicator $indicator, InsightQueryRequest $request): array
    {
        return [
            'metric' => $request->string('metric')->toString() ?: 'net_sales',
            // Achado do frontend: faltava o prefixo `professional/` — a rota real é
            // `GET professional/insights/{indicator}/drill-down`, não `insights/...` solto.
            'drill_down_url' => "/api/professional/insights/{$indicator->value}/drill-down",
            'export_url' => "/api/professional/insights/{$indicator->value}/export",
        ];
    }
}
