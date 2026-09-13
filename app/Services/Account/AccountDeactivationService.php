<?php

namespace App\Services\Account;

use App\Enums\DeactivationReason;
use App\Exceptions\Account\AccountAlreadyDeactivatedException;
use App\Exceptions\Account\AccountNotDeactivatedException;
use App\Exceptions\Account\AccountSuspendedException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Desativação voluntária de conta (decisão do dono do produto, 2026-09-13): a pessoa parou de
 * usar o 2pets e os dados continuam intactos — é o oposto do direito ao esquecimento da LGPD
 * (`LgpdController::deleteAccount`, que anonimiza a pedido explícito do titular). Aqui NADA é
 * apagado, alterado ou anonimizado: só `deactivated_at`/`deactivation_reason`/
 * `deactivation_note`/`deactivated_by` mudam, e os tokens Sanctum são revogados para barrar o
 * acesso. Todo pet, vacina, agendamento, fatura e prontuário permanece exatamente como estava.
 */
final class AccountDeactivationService
{
    public function deactivate(
        User $user,
        DeactivationReason $reason,
        ?string $note,
        User $performedBy,
    ): void {
        if ($user->isDeactivated()) {
            throw new AccountAlreadyDeactivatedException;
        }

        DB::transaction(function () use ($user, $reason, $note, $performedBy): void {
            $user->update([
                'deactivated_at' => now(),
                'deactivation_reason' => $reason,
                'deactivation_note' => $note,
                'deactivated_by' => $performedBy->id,
            ]);

            $user->tokens()->delete();
        });

        Log::info('Account deactivated', [
            'user_id' => $user->id,
            'performed_by' => $performedBy->id,
            'self_service' => $performedBy->id === $user->id,
            'reason' => $reason->value,
        ]);
    }

    /**
     * Autosserviço: a própria pessoa volta a usar a conta. Uma conta também suspensa
     * (punitiva) não sai da suspensão por aqui — só o admin levanta suspensão.
     */
    public function reactivate(User $user): void
    {
        if (! $user->isDeactivated()) {
            throw new AccountNotDeactivatedException;
        }

        if ($user->is_suspended) {
            throw new AccountSuspendedException;
        }

        $this->clearDeactivation($user);

        Log::info('Account reactivated', ['user_id' => $user->id]);
    }

    private function clearDeactivation(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->update([
                'deactivated_at' => null,
                'deactivation_reason' => null,
                'deactivation_note' => null,
                'deactivated_by' => null,
                'reactivated_at' => now(),
            ]);
        });
    }
}
