<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST data-imports/{id}/execute` — regra 2 da spec 26: duplicidade é decisão do usuário,
 * escolhida ANTES de executar, nunca inferida automaticamente.
 */
class ExecuteDataImportRequest extends FormRequest
{
    private const DUPLICATE_STRATEGIES = ['skip', 'update', 'create_anyway'];

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
            'duplicate_strategy' => ['required', 'string', Rule::in(self::DUPLICATE_STRATEGIES)],
        ];
    }

    /**
     * @return array{duplicate_strategy: string}
     */
    public function options(): array
    {
        return $this->validated();
    }
}
