<?php

namespace App\Http\Requests\Insights;

use App\Enums\InsightIndicator;
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
            'indicator_key' => ['required', Rule::enum(InsightIndicator::class)],
            'config' => ['required', 'array'],
            'position' => ['nullable', 'integer', 'min:0'],
            'size' => ['nullable', 'string', Rule::in(['small', 'medium', 'large'])],
        ];
    }
}
