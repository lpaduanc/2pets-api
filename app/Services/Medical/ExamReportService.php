<?php

namespace App\Services\Medical;

use App\Models\Exam;

/**
 * Finalização do laudo — contrato docs/gap-simplesvet/specs/16-modelos-exame-laudos-spec.md,
 * regra de negócio 3/5. Quem assina é sempre quem está autenticado no momento de finalizar
 * (decisão já registrada no doc 10 §2, não reaberta aqui) — por isso este service não recebe
 * "profissional que laudou" separado do `Exam::professional_id` já existente.
 *
 * Finalizar o laudo NUNCA trava o fechamento do agendamento: `Exam.status` já é um relógio
 * separado de `Appointment.status` (doc 10 §2) — este service só grava conteúdo, não decide
 * nada sobre o agendamento.
 */
final class ExamReportService
{
    /**
     * @param  array{report_html?: ?string, findings?: ?string, conclusion?: ?string}  $data
     */
    public function finalize(Exam $exam, array $data): Exam
    {
        $exam->forceFill([
            'report_html' => $data['report_html'] ?? $exam->report_html,
            'findings' => $data['findings'] ?? $exam->findings,
            'conclusion' => $data['conclusion'] ?? $exam->conclusion,
            'status' => 'completed',
        ])->save();

        return $exam->fresh();
    }
}
