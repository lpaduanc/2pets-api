<?php

namespace App\Enums;

/**
 * Estado do "Portal do Cliente" mostrado na ficha do cliente (clínica) — contrato
 * `docs/gap-simplesvet/specs/19-portal-do-cliente-spec.md` item 4. Leitura DERIVADA de
 * `User::isUnclaimed()` + existência de um convite já emitido, nunca uma coluna própria.
 */
enum ClientPortalState: string
{
    /** Conta não reivindicada e nenhum convite (link de continuação) foi emitido ainda. */
    case NO_ACCOUNT = 'no_account';

    /** Conta não reivindicada, mas já existe (ou existiu) convite emitido. */
    case INVITED = 'invited';

    /** Conta reivindicada — o cliente já definiu a própria senha e acessa a plataforma. */
    case ACTIVE = 'active';

    public function label(): string
    {
        return match ($this) {
            self::NO_ACCOUNT => 'Sem conta',
            self::INVITED => 'Convidado',
            self::ACTIVE => 'Ativo',
        };
    }
}
