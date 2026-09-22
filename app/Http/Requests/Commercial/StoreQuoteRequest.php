<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Novo orçamento — docs/gap-simplesvet/24-orcamentos.md.
 *
 * Com `medical_record_id` ou `hospitalization_id`, tutor e animal vêm do atendimento e o que
 * vier em `client_id`/`pet_id` é ignorado (`QuoteService::createFromRecord`). Sem origem, é o
 * orçamento de balcão, com tutor opcional até o envio.
 *
 * Tutor ser cliente da clínica e o animal ser dele é checado por `SaleService::create`
 * (`assertCounterparty`), a mesma regra da venda — não repetida aqui.
 */
class StoreQuoteRequest extends FormRequest
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
            'client_id' => ['nullable', 'integer', 'exists:users,id'],
            'pet_id' => ['nullable', 'integer', 'exists:pets,id'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'printed_notes' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'medical_record_id' => ['nullable', 'integer', 'exists:medical_records,id', 'prohibits:hospitalization_id'],
            'hospitalization_id' => ['nullable', 'integer', 'exists:hospitalizations,id'],
        ];
    }
}
