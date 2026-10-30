<?php

namespace App\Http\Requests\Commercial;

use App\Enums\CardBrand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Faixas de taxa de cartão (docs/gap-petflow/PLANO.md, 1.1). Percentual com até 4 casas e taxa
 * fixa com 2 (regra do produto: valor em R$ sempre com 2 casas); sobreposição entre faixas é
 * checada no `FeeTierService`, que conhece o conjunto inteiro.
 *
 * `id` é opcional: com ele a faixa é atualizada no lugar (o `fee_tier_id` dos recebimentos não
 * se perde); sem ele, é criada. Que o id pertence a ESTA forma é checado no service.
 */
class UpdatePaymentFeeTiersRequest extends FormRequest
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
            'tiers' => ['present', 'array', 'max:60'],
            'tiers.*.id' => ['nullable', 'integer', 'distinct'],
            'tiers.*.card_brand' => ['nullable', Rule::in(CardBrand::values())],
            'tiers.*.installments_from' => ['required', 'integer', 'min:1', 'max:24'],
            'tiers.*.installments_to' => ['required', 'integer', 'min:1', 'max:24'],
            'tiers.*.fee_percent' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'tiers.*.fee_fixed' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'],
            'tiers.*.first_settlement_days' => ['required', 'integer', 'min:0', 'max:365'],
            'tiers.*.interval_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'tiers.*.pass_to_customer' => ['nullable', 'boolean'],
            'tiers.*.valid_from' => ['nullable', 'date_format:Y-m-d'],
            'tiers.*.valid_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function tiers(): array
    {
        return array_values(array_map(fn (array $tier): array => [
            'id' => isset($tier['id']) ? (int) $tier['id'] : null,
            'card_brand' => $tier['card_brand'] ?? null,
            'installments_from' => (int) $tier['installments_from'],
            'installments_to' => (int) $tier['installments_to'],
            'fee_percent' => (string) $tier['fee_percent'],
            'fee_fixed' => (string) ($tier['fee_fixed'] ?? '0'),
            'first_settlement_days' => (int) $tier['first_settlement_days'],
            'interval_days' => (int) ($tier['interval_days'] ?? 30),
            'pass_to_customer' => (bool) ($tier['pass_to_customer'] ?? false),
            'valid_from' => $tier['valid_from'] ?? null,
            'valid_to' => $tier['valid_to'] ?? null,
        ], $this->validated('tiers')));
    }
}
