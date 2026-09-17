<?php

namespace App\Enums;

/**
 * Estado de uma internação (`hospitalizations.status`). Contrato
 * docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §3: alta,
 * transferência e óbito fecham a estadia igualmente — nenhum dos três é tratado como
 * exceção de cobrança.
 *
 * `hospitalizations.status` é `varchar` + CHECK (mesmo padrão de `AppointmentStatus`/
 * `InvoiceStatus`): não é usado como `casts()` do model, para não travar leitura antiga.
 */
enum HospitalizationStatus: string
{
    case ACTIVE = 'active';
    case DISCHARGED = 'discharged';
    case TRANSFERRED = 'transferred';
    case DECEASED = 'deceased';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Internado',
            self::DISCHARGED => 'Alta',
            self::TRANSFERRED => 'Transferido',
            self::DECEASED => 'Óbito',
        };
    }

    /**
     * As três saídas da internação (alta, transferência, óbito) fecham o agendamento da
     * estadia igualmente — contrato §3. `ACTIVE` é o único estado que mantém a comanda
     * aberta.
     */
    public function isClosed(): bool
    {
        return $this !== self::ACTIVE;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
