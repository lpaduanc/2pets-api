<?php

namespace App\Services\Medical;

use App\Models\MedicalRecord;
use Illuminate\Support\Collection;

/**
 * Monta o texto de `summary_for_tutor` por template determinístico — sem IA (contrato
 * docs/atendimento-veterinario/05-contrato-consulta-sem-digitacao.md §6). NUNCA persiste:
 * `POST professional/medical-records/{id}/summary-preview` só devolve o texto, quem grava é o
 * `PUT` de rascunho normal, quando o vet confirma.
 *
 * Regra de ouro: uma frase só entra se o campo estruturado que a origina foi preenchido. Campo
 * vazio nunca vira frase vazia, "N/A" ou achado inventado — simplesmente some da montagem
 * (doc 04 §6.1). Quando NENHUM campo estruturado foi preenchido, o texto cai no §6.2 item 1:
 * uma frase mínima honesta, nunca uma paráfrase automática do texto livre (isso seria geração
 * de linguagem, não montagem — o risco que este service existe justamente para não correr).
 *
 * Cobertura deliberadamente parcial (decisão do backend-specialist, autorizada pelo dono do
 * produto: "prefiro um template que cubra menos e leia bem"): `body_condition_score` e
 * `pain_score` não entram no texto (são escalas técnicas, não frase natural), e
 * `treatment_actions.item = 'other'` não tem rótulo — some da lista de conduta em vez de expor
 * a chave crua. O jargão de `diagnosis` em texto livre também não é traduzido (doc 04 §6.2
 * item 2, aceito como limitação conhecida).
 */
final class MedicalRecordSummaryPreviewService
{
    public function preview(MedicalRecord $medicalRecord): string
    {
        $medicalRecord->loadMissing(['pet', 'followUpAppointment']);

        $openingSign = $medicalRecord->chief_complaint ?? $this->firstAnamnesisSign($medicalRecord);

        $sentences = array_filter([
            $openingSign ? $this->complaintSentence($medicalRecord, $openingSign) : null,
            $openingSign ? $this->additionalSignsSentence($medicalRecord, $openingSign) : null,
            $this->behaviorSentence($medicalRecord),
            $this->physicalExamSentence($medicalRecord),
            $this->diagnosisSentence($medicalRecord),
            $this->treatmentSentence($medicalRecord),
            $this->followUpSentence($medicalRecord),
        ]);

        return $sentences === [] ? $this->fallbackSentence($medicalRecord) : implode(' ', $sentences);
    }

    private function fallbackSentence(MedicalRecord $medicalRecord): string
    {
        $date = $medicalRecord->record_date?->format('d/m/Y') ?? now()->format('d/m/Y');

        return "Consulta realizada em {$date}. Consulte os detalhes técnicos do atendimento.";
    }

    private function complaintSentence(MedicalRecord $medicalRecord, string $sign): string
    {
        $petName = $medicalRecord->pet?->name ?? 'o pet';
        $label = $this->signLabel($sign);
        $onset = $this->onsetForSign($medicalRecord, $sign);
        $onsetClause = $onset ? ", com início {$onset}" : '';

        return "Na consulta de hoje, {$petName} foi atendido(a) por {$label}{$onsetClause}.";
    }

    private function additionalSignsSentence(MedicalRecord $medicalRecord, string $openingSign): ?string
    {
        $others = collect($medicalRecord->anamnesis_signs ?? [])
            ->pluck('sign')
            ->filter(fn (mixed $sign): bool => $sign !== $openingSign)
            ->unique()
            ->map(fn (string $sign): string => $this->signLabel($sign))
            ->values();

        return $others->isEmpty() ? null : 'Também foram relatados: '.$others->join(', ', ' e ').'.';
    }

    private function behaviorSentence(MedicalRecord $medicalRecord): ?string
    {
        $deviations = collect($medicalRecord->behavior_findings ?? [])
            ->reject(fn (mixed $value): bool => $value === null || $value === 'normal')
            ->map(fn (string $value, string $key): ?string => $this->behaviorLabel($key, $value))
            ->filter()
            ->values();

        return $deviations->isEmpty() ? null : 'No comportamento, chamou atenção: '.$deviations->join(', ', ' e ').'.';
    }

    private function physicalExamSentence(MedicalRecord $medicalRecord): ?string
    {
        $findings = $this->abnormalSystemFindings($medicalRecord)->merge($this->abnormalVitalFindings($medicalRecord));

        return $findings->isEmpty() ? null : 'No exame, '.$findings->join(', ', ' e ').'.';
    }

    private function abnormalSystemFindings(MedicalRecord $medicalRecord): Collection
    {
        $baseline = config('clinical-summary-labels.physical_exam_baseline');

        return collect($medicalRecord->physical_exam ?? [])
            ->except('notes')
            ->reject(fn (mixed $value, string $system): bool => in_array($value, $baseline[$system] ?? ['normal'], true))
            ->map(fn (mixed $value, string $system): ?string => $this->physicalExamLabel($system, (string) $value))
            ->filter()
            ->values();
    }

    private function abnormalVitalFindings(MedicalRecord $medicalRecord): Collection
    {
        $items = collect();

        if ($medicalRecord->hydration_status && $medicalRecord->hydration_status !== 'normal') {
            $items->push($this->physicalExamLabel('hydration_status', $medicalRecord->hydration_status));
        }

        if ($medicalRecord->capillary_refill_time && $medicalRecord->capillary_refill_time !== 'lt_2s') {
            $items->push($this->physicalExamLabel('capillary_refill_time', $medicalRecord->capillary_refill_time));
        }

        return $items->filter();
    }

    private function diagnosisSentence(MedicalRecord $medicalRecord): ?string
    {
        if (! filled($medicalRecord->diagnosis)) {
            return null;
        }

        $statusLabel = $medicalRecord->diagnosis_status
            ? config("clinical-summary-labels.diagnosis_status.{$medicalRecord->diagnosis_status}")
            : null;

        return $statusLabel
            ? "O diagnóstico foi {$statusLabel}: {$medicalRecord->diagnosis}."
            : "O diagnóstico foi: {$medicalRecord->diagnosis}.";
    }

    private function treatmentSentence(MedicalRecord $medicalRecord): ?string
    {
        $items = collect($medicalRecord->treatment_actions ?? [])
            ->map(fn (array $action): ?string => $this->treatmentActionLabel($action['category'] ?? null, $action['item'] ?? null))
            ->filter()
            ->values();

        return $items->isEmpty() ? null : 'A conduta foi: '.$items->join(', ', ' e ').'.';
    }

    private function followUpSentence(MedicalRecord $medicalRecord): ?string
    {
        $date = $medicalRecord->followUpAppointment?->appointment_date;

        return $date ? "Retorno agendado para {$date->format('d/m')}." : null;
    }

    private function firstAnamnesisSign(MedicalRecord $medicalRecord): ?string
    {
        $first = collect($medicalRecord->anamnesis_signs ?? [])->first();

        return $first['sign'] ?? null;
    }

    private function onsetForSign(MedicalRecord $medicalRecord, string $sign): ?string
    {
        $entry = collect($medicalRecord->anamnesis_signs ?? [])->firstWhere('sign', $sign);
        $onset = $entry['onset'] ?? null;

        return $onset ? config("clinical-summary-labels.onset_labels.{$onset}") : null;
    }

    private function signLabel(string $sign): string
    {
        return config("clinical-summary-labels.sign_labels.{$sign}", $sign);
    }

    private function behaviorLabel(string $key, string $value): ?string
    {
        return config("clinical-summary-labels.behavior_findings.{$key}.{$value}");
    }

    private function physicalExamLabel(string $system, string $value): ?string
    {
        return config("clinical-summary-labels.physical_exam.{$system}.{$value}");
    }

    private function treatmentActionLabel(?string $category, ?string $item): ?string
    {
        if ($category === null || $item === null) {
            return null;
        }

        return config("clinical-summary-labels.treatment_actions.{$category}.{$item}");
    }
}
