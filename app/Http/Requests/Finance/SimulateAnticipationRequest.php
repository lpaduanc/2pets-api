<?php

namespace App\Http\Requests\Finance;

use App\Enums\AnticipationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Simulação de antecipação (PLANO 2.1): parcelas, data (hoje ou depois — e nunca depois da data
 * de nenhuma parcela, conferido no service, que conhece as datas), modo e taxa (> 0, até 30%:
 * acima disso é erro de digitação, não taxa de adquirente).
 */
class SimulateAnticipationRequest extends FormRequest
{
    public const MAX_RATE = 30;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'receivable_ids' => ['required', 'array', 'min:1', 'max:500'],
            'receivable_ids.*' => ['integer', 'distinct'],
            'anticipated_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.\App\Support\BusinessClock::todayString()],
            'mode' => ['required', Rule::enum(AnticipationMode::class)],
            'rate' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:'.self::MAX_RATE],
        ];
    }
}
