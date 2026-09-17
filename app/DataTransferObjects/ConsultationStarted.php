<?php

namespace App\DataTransferObjects;

use App\Models\Appointment;
use App\Models\Exam;
use App\Models\MedicalRecord;

/**
 * Resultado de `ConsultationService::start()`/`startWalkIn()` — o trio (agendamento,
 * rascunho, exame) que a rota devolve junto (contrato §1 "Regra de resposta").
 *
 * `medicalRecord` é nulo para atendimento não clínico ou de exame (contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.4: banho e tosa não
 * gera prontuário — não há ato clínico nem CRMV assinando). `exam` é nulo em todos os
 * outros casos (contrato docs/atendimento-veterinario/
 * 10-taxonomia-servico-tipo-atendimento.md §2/§3): os dois nunca vêm preenchidos juntos.
 */
final readonly class ConsultationStarted
{
    public function __construct(
        public Appointment $appointment,
        public ?MedicalRecord $medicalRecord,
        public ?Exam $exam = null,
    ) {}
}
