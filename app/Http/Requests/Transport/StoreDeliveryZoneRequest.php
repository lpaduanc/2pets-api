<?php

namespace App\Http\Requests\Transport;

use App\Models\DeliveryZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Zona do leva e traz (PLANO 3.7). Serve store e update (`sometimes` no update). */
class StoreDeliveryZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'rule_type' => [$required, Rule::in([DeliveryZone::RULE_CEP, DeliveryZone::RULE_DISTANCE])],
            'ceps' => ['required_if:rule_type,cep', 'nullable', 'array', 'max:200'],
            'ceps.*' => ['string', 'regex:/^\d{5}-?\d{3}(\.\.\d{5}-?\d{3})?$/'],
            'max_km' => ['required_if:rule_type,distance', 'nullable', 'numeric', 'gt:0', 'max:500'],
            'fee' => [$required, 'numeric', 'decimal:0,2', 'min:0', 'max:99999'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Regra coerente com o tipo: zona de CEP não guarda raio e vice-versa. CEPs normalizados
     * ("01000000..01999999" → "01000-000..01999-999").
     *
     * @return array<string, mixed>
     */
    public function zoneAttributes(): array
    {
        $data = $this->validated();

        if (! array_key_exists('rule_type', $data)) {
            return $data;
        }

        $isCep = $data['rule_type'] === DeliveryZone::RULE_CEP;
        $data['ceps'] = $isCep ? array_values(array_map(fn (string $rule): string => self::normalizeCepRule($rule), $data['ceps'] ?? [])) : null;
        $data['max_km'] = $isCep ? null : $data['max_km'];

        return $data;
    }

    private static function normalizeCepRule(string $rule): string
    {
        return implode('..', array_map(function (string $part): string {
            $digits = preg_replace('/\D/', '', $part);

            return substr($digits, 0, 5).'-'.substr($digits, 5, 3);
        }, explode('..', $rule)));
    }
}
