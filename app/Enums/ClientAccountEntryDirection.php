<?php

namespace App\Enums;

/**
 * `client_account_entries.direction` — contrato docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md.
 *
 * O saldo (`company_client_accounts.current_balance`) é um único número com sinal: positivo =
 * a clínica deve ao cliente (ele adiantou), negativo = o cliente deve à clínica (fiado).
 * `CREDIT` sempre move o saldo em direção a positivo; `DEBIT` sempre em direção a negativo —
 * **nunca infira a direção pelo nome do `type`** (`payment_credit` é `direction = debit`: o
 * cliente está consumindo um saldo credor que já tinha, ver `ClientAccountEntryType`).
 */
enum ClientAccountEntryDirection: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';

    /** Sinal a aplicar sobre o valor absoluto do lançamento ao atualizar o saldo em cache. */
    public function sign(): int
    {
        return $this === self::CREDIT ? 1 : -1;
    }

    public function label(): string
    {
        return match ($this) {
            self::DEBIT => 'Débito',
            self::CREDIT => 'Crédito',
        };
    }
}
