<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

/**
 * Contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * Venda de balcão é operação de RECEPÇÃO: qualquer membro ativo da organização vende, recebe e
 * consulta. É deliberadamente mais permissivo que o módulo clínico — vender ração não é ato
 * privativo de veterinário, e exigir cargo para operar o caixa emperraria o balcão.
 *
 * O que NÃO é de todo mundo é CANCELAR venda já paga: isso estorna dinheiro e devolve estoque,
 * e fica com o dono (ou com quem criou, enquanto a venda ainda não foi paga).
 */
class SalePolicy
{
    public function view(User $user, Sale $sale): bool
    {
        return $this->belongsToUserScope($user, $sale);
    }

    public function update(User $user, Sale $sale): bool
    {
        return $sale->status->isEditable() && $this->belongsToUserScope($user, $sale);
    }

    public function registerReceipt(User $user, Sale $sale): bool
    {
        return $this->belongsToUserScope($user, $sale);
    }

    /**
     * Cancelar venda PAGA é ato de dono: mexe em caixa fechado do dia e em estoque. Venda
     * ainda não paga pode ser cancelada por quem a criou — é só descartar um rascunho de
     * balcão, não desfazer um fato financeiro.
     */
    public function cancel(User $user, Sale $sale): bool
    {
        if (! $this->belongsToUserScope($user, $sale)) {
            return false;
        }

        if (! $sale->status->isSettled()) {
            return $sale->created_by === $user->id || $this->ownsScope($user, $sale);
        }

        return $this->ownsScope($user, $sale);
    }

    private function belongsToUserScope(User $user, Sale $sale): bool
    {
        if ($sale->organization_id !== null) {
            return $user->isActiveMemberOfOrganization($sale->organization_id);
        }

        return $sale->professional_id === $user->id;
    }

    private function ownsScope(User $user, Sale $sale): bool
    {
        if ($sale->organization_id === null) {
            return $sale->professional_id === $user->id;
        }

        return $user->ownsOrganization($sale->organization_id);
    }
}
