<?php

namespace App\Services\Import;

use App\Models\User;

/**
 * Cliente por CPF/e-mail/telefone (item 26 do backlog gap-simplesvet). CPF tem prioridade —
 * é o identificador mais confiável; e-mail e telefone são fallback para planilha sem CPF.
 */
final class ClientDuplicateDetector
{
    /**
     * @param  array{cpf: ?string, email: ?string, phone: ?string}  $normalized
     */
    public function findExisting(array $normalized): ?User
    {
        if (! empty($normalized['cpf'])) {
            $byCpf = User::where('cpf', $normalized['cpf'])->first();
            if ($byCpf !== null) {
                return $byCpf;
            }
        }

        if (! empty($normalized['email'])) {
            $byEmail = User::where('email', $normalized['email'])->first();
            if ($byEmail !== null) {
                return $byEmail;
            }
        }

        if (! empty($normalized['phone'])) {
            return User::where('phone', $normalized['phone'])->first();
        }

        return null;
    }
}
