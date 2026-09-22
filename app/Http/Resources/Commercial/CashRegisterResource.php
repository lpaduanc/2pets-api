<?php

namespace App\Http\Resources\Commercial;

use App\Enums\CashRegisterStatus;
use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin CashRegister
 */
class CashRegisterResource extends JsonResource
{
    public const RESOURCE_RELATIONS = ['openedBy:id,name', 'closedBy:id,name', 'settledBy:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $abilities = $this->abilitiesFor($request->user());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'accepts_movements' => $this->status->acceptsMovements(),

            'opened_by' => $this->whenLoaded('openedBy', fn () => [
                'id' => $this->openedBy->id,
                'name' => $this->openedBy->name,
            ]),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'opening_amount' => (float) $this->opening_amount,

            'closed_by' => $this->whenLoaded('closedBy', fn () => $this->closedBy ? [
                'id' => $this->closedBy->id,
                'name' => $this->closedBy->name,
            ] : null),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closing_amount' => $this->closing_amount === null ? null : (float) $this->closing_amount,
            'counted_amount' => $this->counted_amount === null ? null : (float) $this->counted_amount,
            'difference' => $this->difference === null ? null : (float) $this->difference,
            'closing_breakdown' => $this->breakdownRows(),

            'settled_by' => $this->whenLoaded('settledBy', fn () => $this->settledBy ? [
                'id' => $this->settledBy->id,
                'name' => $this->settledBy->name,
            ] : null),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'review_reason' => $this->review_reason,
            'notes' => $this->notes,

            'abilities' => $abilities,

            // Só quando os movimentos vieram carregados: a listagem de caixas não precisa
            // somar o extrato de cada um, e forçar isso seria N+1 garantido.
            //
            // CONFERÊNCIA CEGA: com o caixa ainda aberto, o esperado só aparece para quem
            // confere (`preview`). O operador conta a gaveta sem saber quanto "deveria" dar —
            // senão a contagem vira cópia do número da tela (ver `CashRegisterService::close()`).
            'totals' => $this->when(
                $this->relationLoaded('movements') && (! $this->isOpen() || $abilities['preview']),
                fn (): array => [
                    'supplies' => $this->totalSupplies(),
                    'withdrawals' => $this->totalWithdrawals(),
                    'sales' => $this->totalSales(),
                    'expected_cash' => $this->expectedCashAmount(),
                ]
            ),

            'movements' => CashRegisterMovementResource::collection($this->whenLoaded('movements')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * `closing_breakdown` é gravado como mapa `{payment_method_id|none: {...}}`, mas sai como
     * LISTA com o id dentro de cada linha: o `JsonResource` reindexa todo array de chave
     * numérica (`array_values`), e o mapa chegava ao app como lista sem saber de qual forma
     * era cada linha. O nome vai junto para a tela não precisar cruzar com outra consulta.
     *
     * @return list<array{payment_method_id: int|null, payment_method_name: string, expected: float, counted: float, difference: float}>|null
     */
    private function breakdownRows(): ?array
    {
        if ($this->closing_breakdown === null) {
            return null;
        }

        $ids = array_filter(array_keys($this->closing_breakdown), 'is_numeric');
        $names = PaymentMethod::withTrashed()->whereKey($ids)->pluck('name', 'id');

        $rows = [];

        foreach ($this->closing_breakdown as $key => $row) {
            $id = is_numeric($key) ? (int) $key : null;

            $rows[] = [
                'payment_method_id' => $id,
                'payment_method_name' => $id === null ? 'Sem forma de pagamento' : ($names[$id] ?? 'Forma removida'),
                'expected' => (float) $row['expected'],
                'counted' => (float) $row['counted'],
                'difference' => (float) $row['difference'],
            ];
        }

        return $rows;
    }

    /**
     * O que o usuário pode fazer com ESTE caixa, para o app decidir quais botões mostrar sem
     * reimplementar `CashRegisterPolicy`. `settle`, `review` e `preview` são a mesma
     * autoridade na policy, então a pergunta é feita uma vez só (`ownsOrganization` é query).
     *
     * @return array{operate: bool, close: bool, settle: bool, review: bool, preview: bool}
     */
    private function abilitiesFor(?User $user): array
    {
        if ($user === null) {
            return ['operate' => false, 'close' => false, 'settle' => false, 'review' => false, 'preview' => false];
        }

        $gate = Gate::forUser($user);
        $canConfer = $gate->allows('settle', $this->resource);

        return [
            'operate' => $this->isOpen() && $gate->allows('operate', $this->resource),
            'close' => $this->isOpen() && $gate->allows('close', $this->resource),
            'settle' => $canConfer && $this->status->awaitsSettlement(),
            'review' => $canConfer && $this->status === CashRegisterStatus::CLOSED,
            'preview' => $canConfer,
        ];
    }
}
