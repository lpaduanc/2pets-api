<?php

namespace App\Http\Requests\Insights;

use App\Enums\InsightIndicator;
use App\Services\Insights\Widgets\DashboardWidgetRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `POST dashboard-widgets` — contrato docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md. */
class StoreDashboardWidgetRequest extends FormRequest
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
            // Indicador de BI (`sales`) ou um widget com provider do catálogo (PLANO 3.6).
            'indicator_key' => ['required', 'string', Rule::in([...InsightIndicator::values(), ...app(DashboardWidgetRegistry::class)->keys()])],
            // O indicador de BI exige a configuração (dimensão/métrica); o widget com provider
            // guarda só os parâmetros dele, e pode vir vazio (vale o padrão).
            'config' => [Rule::requiredIf(fn (): bool => in_array($this->input('indicator_key'), InsightIndicator::values(), true)), 'nullable', 'array'],
            'position' => ['nullable', 'integer', 'min:0'],
            'size' => ['nullable', 'string', Rule::in(['small', 'medium', 'large'])],
        ];
    }
}
