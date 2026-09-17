<?php

namespace App\Enums;

/**
 * `hospitalization_care_logs.care_type` — contrato
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §4.2.
 *
 * Taxonomia de mercado, não de norma (a verificação regulatória do doc não achou resolução
 * específica sobre checklist de cuidados de internação) — lista curta e prática, mesma régua
 * de corte já usada para `chief_complaint`/`physical_exam` em `config/clinical-parameters.php`.
 *
 * `varchar` + CHECK no banco (mesmo padrão de `HospitalizationStatus`/`ExamResultStatus`):
 * coluna nova, sem risco de leitura antiga, então o cast direto para este Enum em
 * `HospitalizationCareLog::casts()` é seguro (mesmo padrão de `MedicalRecordStatus`).
 */
enum HospitalizationCareType: string
{
    case FEEDING = 'feeding';
    case MEDICATION_ADMINISTRATION = 'medication_administration';
    case HYGIENE = 'hygiene';
    case MOBILITY = 'mobility';
    case VITAL_MONITORING = 'vital_monitoring';
    case ELIMINATION = 'elimination';
    case WOUND_CARE = 'wound_care';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FEEDING => 'Alimentação/hidratação',
            self::MEDICATION_ADMINISTRATION => 'Medicação administrada',
            self::HYGIENE => 'Higiene/troca de forração',
            self::MOBILITY => 'Mobilização/troca de decúbito',
            self::VITAL_MONITORING => 'Aferição de sinal vital',
            self::ELIMINATION => 'Passeio/necessidade fisiológica',
            self::WOUND_CARE => 'Curativo/ferida',
            self::OTHER => 'Outro',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
