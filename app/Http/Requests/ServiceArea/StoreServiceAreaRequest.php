<?php

namespace App\Http\Requests\ServiceArea;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Item 21 do backlog gap-simplesvet. Serve store e update — `sometimes` no update, mesmo
 * espírito de `StoreProductGroupRequest`.
 */
class StoreServiceAreaRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'color' => ['nullable', 'string', 'size:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'active' => ['nullable', 'boolean'],
            // PLANO 3.7 — zona do leva e traz (contrato do front: `rule_type`, `ceps`, `max_km`, `fee`).
            'delivery_enabled' => ['sometimes', 'boolean'],
            'rule_type' => ['required_if:delivery_enabled,true', 'nullable', Rule::in(['cep', 'distance'])],
            'ceps' => ['required_if:rule_type,cep', 'nullable', 'array', 'max:200'],
            'ceps.*' => ['string', 'regex:/^\d{5}-?\d{3}(\.\.\d{5}-?\d{3})?$/'],
            'max_km' => ['required_if:rule_type,distance', 'nullable', 'numeric', 'gt:0', 'max:500'],
            'fee' => ['required_if:delivery_enabled,true', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999'],
        ];
    }

    /**
     * Campos do contrato → colunas `delivery_*`. Desligado (`delivery_enabled:false`) limpa a regra:
     * área sem leva e traz não guarda faixa nem taxa velhas.
     *
     * @return array<string, mixed>
     */
    public function areaAttributes(): array
    {
        $data = $this->validated();
        $base = array_intersect_key($data, array_flip(['name', 'color', 'active']));

        if (! array_key_exists('delivery_enabled', $data)) {
            return $base;
        }

        if (! $data['delivery_enabled']) {
            return $base + ['delivery_enabled' => false, 'delivery_rule_type' => null, 'delivery_ceps' => null, 'delivery_max_km' => null, 'delivery_fee' => null];
        }

        $isCep = $data['rule_type'] === 'cep';

        return $base + [
            'delivery_enabled' => true,
            'delivery_rule_type' => $data['rule_type'],
            'delivery_ceps' => $isCep ? array_values(array_map(fn (string $rule): string => self::normalizeCepRule($rule), $data['ceps'] ?? [])) : null,
            'delivery_max_km' => $isCep ? null : $data['max_km'],
            'delivery_fee' => $data['fee'],
        ];
    }

    /** "01000000..01999999" → "01000-000..01999-999". */
    private static function normalizeCepRule(string $rule): string
    {
        return implode('..', array_map(function (string $part): string {
            $digits = preg_replace('/\D/', '', $part);

            return substr($digits, 0, 5).'-'.substr($digits, 5, 3);
        }, explode('..', $rule)));
    }
}
