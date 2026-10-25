<?php

namespace App\Http\Requests\Wallet;

use App\Enums\Wallet\WalletPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /wallet — período do resumo e filtro opcional por pet. */
class WalletSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::enum(WalletPeriod::class)],
            'from' => ['required_if:period,custom', 'nullable', 'date'],
            'to' => ['required_if:period,custom', 'nullable', 'date', 'after_or_equal:from'],
            'pet_id' => ['nullable', 'integer', Rule::exists('pets', 'id')->where('user_id', $this->user()->id)],
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function range(CarbonImmutable $today): array
    {
        $period = WalletPeriod::tryFrom((string) $this->input('period')) ?? WalletPeriod::MONTH;

        if ($period !== WalletPeriod::CUSTOM) {
            return $period->range($today);
        }

        return [CarbonImmutable::parse($this->input('from')), CarbonImmutable::parse($this->input('to'))];
    }

    public function petId(): ?int
    {
        return $this->filled('pet_id') ? (int) $this->input('pet_id') : null;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'from.required_if' => 'Informe a data inicial.',
            'to.required_if' => 'Informe a data final.',
            'to.after_or_equal' => 'A data final deve ser depois da inicial.',
            'pet_id.exists' => 'Escolha um dos seus pets.',
        ];
    }
}
