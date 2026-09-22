<?php

namespace App\Http\Requests\Insights;

use App\DataTransferObjects\InsightQuery;
use App\Enums\InsightDimension;
use App\Enums\InsightGranularity;
use App\Enums\InsightMetric;
use App\Enums\SaleKind;
use App\Services\Insights\ProductivityAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação comum de `GET insights/{indicator}`, `.../drill-down` e `.../export` — os três
 * precisam do MESMO conjunto de filtros para o drill-down provar o agregado (regra de negócio
 * 2 da spec docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md).
 *
 * `filters.employee_id` (achado do frontend, corrigido nesta rodada): passava direto para
 * `InsightQuery` sem checar `bi.view.own` × `.view.any` — quem só tem `.own` conseguia ver
 * (ou detalhar, via drill-down) a venda de outro colaborador, e sem NENHUM filtro via `.own`
 * enxergava a clínica inteira sem restrição. `ProductivityAuthorization::resolveEmployeeIds()`
 * já resolvia exatamente essa regra para `ProductivityController` — reaproveitado aqui, não
 * duplicado.
 */
class InsightQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'granularity' => ['nullable', Rule::enum(InsightGranularity::class)],
            'metric' => ['nullable', Rule::enum(InsightMetric::class)],
            'dimension' => ['required', Rule::enum(InsightDimension::class)],
            'kind' => ['nullable', Rule::enum(SaleKind::class)],
            'filters' => ['nullable', 'array'],
            'filters.employee_id' => ['nullable', 'array'],
            'filters.employee_id.*' => ['integer', 'exists:organization_members,id'],
            'filters.client_id' => ['nullable', 'array'],
            'filters.client_id.*' => ['integer', 'exists:users,id'],
        ];
    }

    public function toInsightQuery(ProductivityAuthorization $authorization): InsightQuery
    {
        $requestedEmployeeIds = array_map('intval', (array) $this->input('filters.employee_id', []));

        return new InsightQuery(
            from: CarbonImmutable::parse($this->string('from')->toString())->startOfDay(),
            to: CarbonImmutable::parse($this->string('to')->toString())->endOfDay(),
            granularity: InsightGranularity::from($this->string('granularity')->toString() ?: InsightGranularity::DAY->value),
            metric: InsightMetric::from($this->string('metric')->toString() ?: InsightMetric::NET_SALES->value),
            dimension: InsightDimension::from($this->string('dimension')->toString()),
            kind: SaleKind::from($this->string('kind')->toString() ?: SaleKind::SALE->value),
            // Nunca os `filters.employee_id` crus do request — sempre resolvidos por quem tem
            // autoridade para pedi-los (regra de negócio 5 da spec 20).
            employeeIds: $authorization->resolveEmployeeIds($this->user(), $requestedEmployeeIds),
            clientIds: array_map('intval', (array) $this->input('filters.client_id', [])),
        );
    }
}
