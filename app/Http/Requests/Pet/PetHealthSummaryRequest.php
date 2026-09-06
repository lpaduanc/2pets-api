<?php

namespace App\Http\Requests\Pet;

use Illuminate\Foundation\Http\FormRequest;

class PetHealthSummaryRequest extends FormRequest
{
    /** Same horizon the dashboard already used client-side. */
    private const DEFAULT_WINDOW_DAYS = 90;

    private const MAX_WINDOW_DAYS = 365;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'window_days' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_WINDOW_DAYS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'window_days.integer' => 'A janela de dias deve ser um número inteiro.',
            'window_days.min' => 'A janela de dias deve ser de pelo menos 1 dia.',
            'window_days.max' => 'A janela de dias não pode passar de :max dias.',
        ];
    }

    public function windowDays(): int
    {
        return (int) ($this->validated('window_days') ?? self::DEFAULT_WINDOW_DAYS);
    }
}
