<?php

namespace App\Enums;

/**
 * Tipos de teleatendimento — Resolução CFMV 1.465/2023.
 * Cada tipo tem regras distintas de vínculo prévio entre profissional e paciente/tutor.
 */
enum TeleatendimentoType: string
{
    case Teletriagem = 'teletriagem';
    case Teleconsulta = 'teleconsulta';
    case Teleinterconsulta = 'teleinterconsulta';
    case Telemonitoramento = 'telemonitoramento';

    /** Exige consulta presencial prévia nos últimos 180 dias? */
    public function requiresPriorAppointment(): bool
    {
        return match ($this) {
            self::Teleconsulta, self::Telemonitoramento => true,
            self::Teletriagem, self::Teleinterconsulta => false,
        };
    }

    /** É atendimento entre dois profissionais (não envolve tutor diretamente)? */
    public function isBetweenVets(): bool
    {
        return $this === self::Teleinterconsulta;
    }
}
