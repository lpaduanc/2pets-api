<?php

namespace App\Http\Requests\Commercial;

use App\Enums\PromotionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Promoção (PLANO 3.2): campos comuns + `config` por tipo. Store e update (no update, tudo
 * `sometimes`). Que os produtos/serviços/grupos/marcas do `config` são da organização é conferido
 * em `PromotionConfigScope` (o `exists` puro aceitaria o de outra organização).
 */
class StorePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        // Na edição sem `type`, o `config` é validado pelo tipo que a promoção já tem.
        $type = $this->input('type') ?? \App\Models\Promotion::query()->whereKey($this->route('id'))->first()?->type?->value;

        return [
            'name' => [$required, 'string', 'max:120'],
            'type' => [$required, Rule::enum(PromotionType::class)],
            'starts_at' => [$required, 'date_format:Y-m-d'],
            'ends_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_at'],
            'priority' => ['sometimes', 'integer', 'between:-1000,1000'],
            'stackable' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'config' => [$required, 'array'],
            // Na edição, o `config` só é conferido quando vem.
            ...($this->has('config') ? $this->configRules($type) : []),
        ];
    }

    /** @return array<string, mixed> */
    private function configRules(?string $type): array
    {
        return match ($type) {
            PromotionType::BUY_X_PAY_Y->value => [
                'config.buy' => ['required', 'integer', 'min:2', 'max:100'],
                'config.pay' => ['required', 'integer', 'min:0', 'lt:config.buy'],
                'config.targets' => ['required', 'array', 'min:1', 'max:100'],
                'config.targets.*.type' => ['required', Rule::in(['product', 'service', 'group', 'brand'])],
                'config.targets.*.id' => ['required', 'integer'],
            ],
            PromotionType::BUNDLE->value => [
                'config.items' => ['required', 'array', 'min:2', 'max:20'],
                'config.items.*.sellable_type' => ['required', Rule::in(['product', 'service'])],
                'config.items.*.sellable_id' => ['required', 'integer'],
                'config.items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
                'config.discount_type' => ['required', Rule::in(['percent', 'fixed'])],
                'config.discount_value' => ['required', 'numeric', 'gt:0', Rule::when($this->input('config.discount_type') === 'percent', ['max:100'])],
            ],
            PromotionType::CATEGORY_DISCOUNT->value => [
                'config.group_id' => ['required', 'integer'],
                'config.percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            ],
            PromotionType::BRAND_DISCOUNT->value => [
                'config.brand_id' => ['required', 'integer'],
                'config.percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            ],
            default => [],
        };
    }
}
