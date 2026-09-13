<?php

namespace App\Enums;

use App\Models\User;

/**
 * Rótulo único de "estado da conta", derivado de três colunas independentes de `users`
 * (`is_suspended`, `deactivated_at`, `deleted_at`) — NUNCA armazenado. Cada coluna continua
 * sendo escrita e lida no lugar de sempre; isto existe só para o admin (e qualquer leitor)
 * terem UM campo para exibir em vez de reimplementar a prioridade entre elas toda vez.
 *
 * Prioridade, da mais para a menos severa: suspensão (punitiva, decidida pelo admin) vence
 * desativação (voluntária) quando as duas coexistem — ver `AccountDeactivationService`.
 */
enum AccountStatus: string
{
    case ACTIVE = 'active';
    case DEACTIVATED = 'deactivated';
    case SUSPENDED = 'suspended';

    public static function fromUser(User $user): self
    {
        if ($user->is_suspended) {
            return self::SUSPENDED;
        }

        if ($user->isDeactivated()) {
            return self::DEACTIVATED;
        }

        return self::ACTIVE;
    }

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Ativa',
            self::DEACTIVATED => 'Desativada',
            self::SUSPENDED => 'Suspensa',
        };
    }
}
