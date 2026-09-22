<?php

namespace App\Http\Requests\Insights;

use App\DataTransferObjects\InsightQuery;
use App\Enums\InsightDimension;
use App\Enums\InsightGranularity;
use App\Enums\InsightMetric;
use App\Enums\SaleKind;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET insights/productivity` e `GET me/productivity` — mesma janela de período de
 * `InsightQueryRequest`, sem `dimension` (sempre `employee`, ver `ProductivityService`).
 * `employee_id[]` aqui é quem o CHAMADOR quer consultar — `ProductivityAuthorization` decide
 * se ele pode.
 */
class ProductivityQueryRequest extends FormRequest
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
            'metric' => ['nullable', Rule::enum(InsightMetric::class)],
            'kind' => ['nullable', Rule::enum(SaleKind::class)],
            'employee_id' => ['nullable', 'array'],
            'employee_id.*' => ['integer', 'exists:organization_members,id'],
        ];
    }

    public function toInsightQuery(): InsightQuery
    {
        return new InsightQuery(
            from: CarbonImmutable::parse($this->string('from')->toString())->startOfDay(),
            to: CarbonImmutable::parse($this->string('to')->toString())->endOfDay(),
            granularity: InsightGranularity::DAY,
            metric: InsightMetric::from($this->string('metric')->toString() ?: InsightMetric::NET_SALES->value),
            dimension: InsightDimension::EMPLOYEE,
            kind: SaleKind::from($this->string('kind')->toString() ?: SaleKind::SALE->value),
        );
    }

    /**
     * @return list<int>
     */
    public function requestedEmployeeIds(): array
    {
        return array_map('intval', (array) $this->input('employee_id', []));
    }
}
