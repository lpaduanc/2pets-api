<?php

namespace App\Enums;

/**
 * `hospitalization_care_logs.status` — contrato
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §4.3.
 *
 * Decisão deliberada de registrar a FALHA, não só o sucesso: um checklist que só aceita
 * "feito" torna indistinguíveis "foi feito e ninguém registrou", "nunca foi atribuído" e
 * "foi pulado por um motivo real" — só a terceira, registrada com justificativa
 * (`requiresJustification()`), é defensável depois. `NOT_APPLICABLE` existe separado de
 * `NOT_DONE` para não forçar explicação de um cuidado que nunca fazia parte do plano deste
 * paciente (ex.: `medication_administration` sem nenhuma prescrição ativa).
 */
enum HospitalizationCareStatus: string
{
    case DONE = 'done';
    case NOT_DONE = 'not_done';
    case NOT_APPLICABLE = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::DONE => 'Feito',
            self::NOT_DONE => 'Não feito',
            self::NOT_APPLICABLE => 'Não se aplica',
        };
    }

    /** Contrato §4.3: motivo obrigatório quando o cuidado não foi realizado. */
    public function requiresJustification(): bool
    {
        return $this === self::NOT_DONE;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
