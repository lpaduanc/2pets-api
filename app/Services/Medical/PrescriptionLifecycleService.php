<?php

namespace App\Services\Medical;

use App\Exceptions\Medical\PrescriptionLifecycleException;
use App\Models\MedicalRecord;
use App\Models\Prescription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Máquina de estados da prescrição (contrato docs/atendimento-veterinario/03-contrato-receituario.md
 * §1): emitir, cancelar, e as duas cascatas automáticas que o fluxo de atendimento dispara
 * (emitir junto com `finalize()`, descartar junto com o rascunho abandonado).
 */
final class PrescriptionLifecycleService
{
    public function __construct(private readonly PrescriptionReminderScheduler $reminderScheduler) {}

    /** `POST prescriptions/{id}/issue` — só para prescrição standalone (sem `medical_record_id`). */
    public function issue(Prescription $prescription): Prescription
    {
        $this->assertCanIssue($prescription);

        return DB::transaction(function () use ($prescription): Prescription {
            $prescription->forceFill(['issued_at' => now()])->save();
            $this->reminderScheduler->scheduleForPrescription($prescription);

            return $prescription->refresh();
        });
    }

    /** `POST prescriptions/{id}/cancel` — só para prescrição já emitida. */
    public function cancel(Prescription $prescription, User $canceledBy, string $reason): Prescription
    {
        if (! $prescription->isIssued()) {
            throw PrescriptionLifecycleException::notIssuedYet();
        }

        if ($prescription->isCanceled()) {
            throw PrescriptionLifecycleException::alreadyCanceled();
        }

        $prescription->forceFill([
            'canceled_at' => now(),
            'canceled_reason' => $reason,
            'canceled_by' => $canceledBy->id,
        ])->save();

        return $prescription->refresh();
    }

    /**
     * Chamado DENTRO da mesma transação de `MedicalRecordFinalizationService::finalize()`
     * (contrato §1): toda prescrição vinculada ainda não emitida vira `issued_at = $issuedAt`,
     * o mesmo instante da finalização do prontuário.
     */
    public function issueForMedicalRecord(MedicalRecord $medicalRecord, Carbon $issuedAt): void
    {
        Prescription::query()
            ->where('medical_record_id', $medicalRecord->id)
            ->notIssued()
            ->get()
            ->each(function (Prescription $prescription) use ($issuedAt): void {
                $prescription->forceFill(['issued_at' => $issuedAt])->save();
                $this->reminderScheduler->scheduleForPrescription($prescription);
            });
    }

    /**
     * Descarte de um `MedicalRecord` `draft` (contrato §1): as prescrições daquele
     * atendimento que nunca chegaram a ser emitidas somem junto — uma prescrição de um
     * atendimento que nunca existiu não tem validade nenhuma para continuar visível.
     */
    public function discardDraftPrescriptions(MedicalRecord $medicalRecord): void
    {
        Prescription::query()
            ->where('medical_record_id', $medicalRecord->id)
            ->notIssued()
            ->get()
            ->each(fn (Prescription $prescription) => $prescription->delete());
    }

    private function assertCanIssue(Prescription $prescription): void
    {
        if (! $prescription->isStandalone()) {
            throw PrescriptionLifecycleException::notStandalone();
        }

        if ($prescription->isCanceled()) {
            throw PrescriptionLifecycleException::alreadyCanceled();
        }

        if ($prescription->isIssued()) {
            throw PrescriptionLifecycleException::alreadyIssued();
        }
    }
}
