<?php

namespace App\Policies;

use App\Models\CashRegister;
use App\Models\User;

/**
 * Contrato docs/gap-simplesvet/01-caixa-pdv.md: "só o dono do caixa ou `clinic_owner`/
 * `petshop_owner` fecha e encerra".
 *
 * A separação entre FECHAR e ENCERRAR é o ponto: quem opera conta a própria gaveta (fecha),
 * mas o aceite da diferença é de quem responde pelo negócio (encerra). Deixar o operador
 * encerrar o próprio caixa transformaria a conferência em formalidade — ele aceitaria a
 * própria diferença.
 *
 * Um caixa sem organização (vet volante) é do próprio profissional nos dois atos: não há
 * segunda pessoa para conferir, e travar o encerramento deixaria o caixa preso para sempre.
 */
class CashRegisterPolicy
{
    /** Ver o caixa: o operador, ou qualquer membro ativo da organização dele. */
    public function view(User $user, CashRegister $register): bool
    {
        if ($register->opened_by === $user->id) {
            return true;
        }

        return $register->organization_id !== null
            && $user->isActiveMemberOfOrganization($register->organization_id);
    }

    /** Lançar suprimento, sangria ou venda: só quem abriu opera a própria gaveta. */
    public function operate(User $user, CashRegister $register): bool
    {
        return $register->opened_by === $user->id;
    }

    /** Fechar (contar a gaveta): o operador ou o dono. */
    public function close(User $user, CashRegister $register): bool
    {
        return $register->opened_by === $user->id || $this->ownsOrIsSoleOperator($user, $register);
    }

    /** Encerrar (aceitar a diferença): só o dono — ou o próprio, quando não há organização. */
    public function settle(User $user, CashRegister $register): bool
    {
        return $this->ownsOrIsSoleOperator($user, $register);
    }

    /** Devolver para revisão: mesma autoridade de encerrar. */
    public function review(User $user, CashRegister $register): bool
    {
        return $this->settle($user, $register);
    }

    /**
     * Prévia do fechamento — só quem confere. O operador não vê o esperado ANTES de contar
     * (conferência cega; ver `CashRegisterService::close()`).
     */
    public function preview(User $user, CashRegister $register): bool
    {
        return $this->settle($user, $register);
    }

    private function ownsOrIsSoleOperator(User $user, CashRegister $register): bool
    {
        if ($register->organization_id === null) {
            return $register->opened_by === $user->id;
        }

        return $user->ownsOrganization($register->organization_id);
    }
}
