<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md — só marca a
 * liquidação (`paid_at`/`payment_method`/`payment_reference`); não recalcula nada.
 */
class PayCommissionSettlementRequest extends FormRequest
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
            'payment_method' => ['nullable', 'string', 'max:60'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
