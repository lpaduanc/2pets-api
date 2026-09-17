<?php

namespace App\Http\Requests\Hospitalization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md §1.1.
 *
 * `body` é o único campo realmente obrigatório — nenhum sinal vital é exigido (§1.1: a
 * entrada mais comum na prática é só narrativa, sem nenhum vital preenchido). `author_id`/
 * `recorded_at` (quando ausente) são preenchidos pelo sistema, nunca aceitos como confiança
 * cega do payload.
 */
class StoreHospitalizationProgressNoteRequest extends FormRequest
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
            'body' => ['required', 'string'],
            'recorded_at' => ['nullable', 'date'],
            'temperature' => ['nullable', 'numeric', 'between:20,45'],
            'heart_rate' => ['nullable', 'integer', 'min:0'],
            'respiratory_rate' => ['nullable', 'integer', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0.01'],

            // Correção (§1.3): a entrada corrigida precisa pertencer à MESMA internação.
            'corrects_id' => [
                'nullable',
                'integer',
                Rule::exists('hospitalization_progress_notes', 'id')->where('hospitalization_id', $this->route('id')),
            ],
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'body' => ['description' => 'Narrativa da evolução. Único campo obrigatório.'],
            'recorded_at' => ['description' => 'Momento clínico do fato (data e hora). Default: agora.'],
            'temperature' => ['description' => 'Temperatura em °C, quando aferida.'],
            'heart_rate' => ['description' => 'Frequência cardíaca (bpm), quando aferida.'],
            'respiratory_rate' => ['description' => 'Frequência respiratória (mpm), quando aferida.'],
            'weight' => ['description' => 'Peso em kg, quando aferido — alimenta o histórico de peso do pet.'],
            'corrects_id' => ['description' => 'Id de uma entrada anterior desta mesma internação que esta corrige.'],
        ];
    }
}
