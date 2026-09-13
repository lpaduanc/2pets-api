<?php

namespace App\Http\Requests\Exam;

use App\Enums\ExamResultStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `results.*.value` e `results.*.reference_range` continuam texto livre de propósito — há
 * resultado legitimamente não numérico ("Negativo", "Reagente", "<0,1", "Ausente"). O
 * parsing pt-BR (`App\DataTransferObjects\PtBrDecimal`) acontece depois, em
 * `ExamService::addResults`, nunca aqui — Form Request valida forma, não decide dado.
 *
 * Autorização NÃO mora aqui: quem decide se o requisitante pode escrever neste pet é
 * `AuthorizesPetAccess::resolvePetForWrite()`, chamado pelo controller a partir do
 * `pet_id` do exame já existente. `authorize()` só barra requisição sem usuário autenticado.
 */
class AddExamResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'results' => ['required', 'array', 'min:1'],
            'results.*.parameter' => ['required', 'string', 'max:120'],
            'results.*.value' => ['required', 'string', 'max:255'],
            'results.*.unit' => ['nullable', 'string', 'max:40'],
            'results.*.reference_range' => ['nullable', 'string', 'max:255'],
            'results.*.status' => ['nullable', Rule::enum(ExamResultStatus::class)],
        ];
    }
}
