<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md — fecha um
 * período de comissão para um `staff_id`.
 */
class StoreCommissionSettlementRequest extends FormRequest
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
            'staff_id' => ['required', 'integer', 'exists:organization_members,id'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'received_until' => ['required', 'date'],
        ];
    }
}
