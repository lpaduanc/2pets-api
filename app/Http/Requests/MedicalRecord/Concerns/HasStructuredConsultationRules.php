<?php

namespace App\Http\Requests\MedicalRecord\Concerns;

use App\Rules\ValidAnamnesisSigns;
use App\Rules\ValidTreatmentActions;

/**
 * Regras dos campos de "consulta sem digitação" (contrato
 * docs/atendimento-veterinario/05-contrato-consulta-sem-digitacao.md), compartilhadas entre
 * `StoreMedicalRecordRequest` e `UpdateMedicalRecordRequest` — as duas já duplicavam entre si
 * as regras da fatia anterior (`chief_complaint`, `physical_exam`...), mas aquele bloco era
 * pequeno; este é grande o bastante para que duplicar de novo violasse DRY sem necessidade.
 */
trait HasStructuredConsultationRules
{
    /**
     * @return array<string, mixed>
     */
    protected function structuredConsultationRules(): array
    {
        // IMPORTANTE: nenhuma regra `anamnesis_signs.*.<campo>` / `treatment_actions.*.<campo>`
        // pode existir aqui. Descoberto em teste manual via curl: assim que UMA regra
        // aninhada com wildcard existe para um array, o Laravel muda `validated()` para modo
        // restritivo e passa a devolver, de CADA item da lista, só as sub-chaves que têm regra
        // própria — `sign`, `onset`, `content`... (sem regra de wildcard dedicada) somem
        // silenciosamente do array salvo, mesmo a validação passando sem erro. Por isso
        // `ValidAnamnesisSigns`/`ValidTreatmentActions` validam TUDO (incluindo `notes`) por
        // dentro do próprio objeto de regra, e nenhum wildcard irmão é declarado aqui.
        return [
            'anamnesis_signs' => ['nullable', 'array', new ValidAnamnesisSigns],
            ...$this->behaviorFindingsRules(),
            'recent_routine_change' => ['nullable', 'boolean'],
            'recent_routine_change_notes' => ['nullable', 'string', 'max:500'],
            ...$this->contextFlagsRules(),
            'treatment_actions' => ['nullable', 'array', new ValidTreatmentActions],
            'diagnosis_status' => ['nullable', 'string', 'in:'.implode(',', config('clinical-parameters.diagnosis_status'))],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function behaviorFindingsRules(): array
    {
        $keys = config('clinical-parameters.behavior_findings');
        $rules = ['behavior_findings' => ['nullable', 'array:'.implode(',', array_keys($keys))]];

        foreach ($keys as $field => $values) {
            $rules["behavior_findings.{$field}"] = ['nullable', 'in:'.implode(',', $values)];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFlagsRules(): array
    {
        $booleanFlags = config('clinical-parameters.context_flags.boolean_flags');
        $allowedKeys = ['street_access'];

        foreach ($booleanFlags as $flag) {
            $allowedKeys[] = $flag;
            $allowedKeys[] = "{$flag}_notes";
        }

        $rules = [
            'context_flags' => ['nullable', 'array:'.implode(',', $allowedKeys)],
            'context_flags.street_access' => ['nullable', 'in:'.implode(',', config('clinical-parameters.context_flags.street_access'))],
        ];

        foreach ($booleanFlags as $flag) {
            $rules["context_flags.{$flag}"] = ['nullable', 'boolean'];
            $rules["context_flags.{$flag}_notes"] = ['nullable', 'string', 'max:500'];
        }

        return $rules;
    }
}
