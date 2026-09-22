<?php

namespace App\Http\Resources\Commercial;

use App\Models\CashRegisterMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin CashRegisterMovement
 */
class CashRegisterMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $conceal = $this->concealsAmounts($request);

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            // CONFERÊNCIA CEGA: com o caixa aberto, quem não confere vê o lançamento mas não o
            // valor. Somar os movimentos por forma reconstruiria o "esperado" que `totals`
            // esconde (ver `CashRegisterResource`), anulando a contagem às cegas do fechamento.
            'amount' => $conceal ? null : (float) $this->amount,
            'amount_concealed' => $conceal,
            // O front nunca recalcula o sinal: quem decide a direção é o enum no servidor.
            'signed_amount' => $conceal ? null : $this->signedAmount(),
            'is_physical_cash' => $this->isPhysicalCash(),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'description' => $this->description,
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod ? [
                'id' => $this->paymentMethod->id,
                'name' => $this->paymentMethod->name,
                'kind' => $this->paymentMethod->kind->value,
            ] : null),
            'payment_method_id' => $this->payment_method_id,
            'account_id' => $this->account_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'reference_type' => $this->reference_type === null ? null : class_basename($this->reference_type),
            'reference_id' => $this->reference_id,
        ];
    }

    /**
     * Decidido UMA vez por caixa por requisição (a policy de `preview` consulta o banco), e
     * guardado nos atributos da própria request — a coleção inteira pergunta a mesma coisa.
     */
    private function concealsAmounts(Request $request): bool
    {
        $key = 'cash_register.conceal_amounts.'.$this->cash_register_id;

        if (! $request->attributes->has($key)) {
            $register = $this->cashRegister;
            $user = $request->user();

            $request->attributes->set($key, $register !== null
                && $register->isOpen()
                && ! ($user !== null && Gate::forUser($user)->allows('preview', $register)));
        }

        return (bool) $request->attributes->get($key);
    }
}
