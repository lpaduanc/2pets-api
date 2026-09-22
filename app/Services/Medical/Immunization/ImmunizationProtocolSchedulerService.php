<?php

namespace App\Services\Medical\Immunization;

use App\Enums\ImmunizationDoseAnchor;
use App\Enums\PetImmunizationPlanStatus;
use App\Models\ImmunizationProtocolDose;
use App\Models\Pet;
use App\Models\PetImmunizationDose;
use App\Models\PetImmunizationPlan;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Motor de agendamento do grafo de doses — contrato
 * docs/gap-simplesvet/specs/13-protocolos-vacinais-spec.md, regras 3/4/5/6.
 *
 * Nada aqui grava "vencida"/"em dia": `PetImmunizationDose::isOverdue()` deriva isso em
 * leitura. O que este service grava é só `scheduled_for` (a previsão) e, na aplicação,
 * `applied_at`/`applied_by`/`vaccination_id`.
 */
final class ImmunizationProtocolSchedulerService
{
    /**
     * Cria o plano e já materializa TODAS as doses do protocolo (o grafo é pequeno, no
     * máximo 7 doses — spec 13) na ordem de dependência: uma dose só é criada depois da
     * que ela depende, para poder calcular `scheduled_for` a partir da data já persistida.
     */
    public function startPlan(Pet $pet, \App\Models\ImmunizationProtocol $protocol, CarbonInterface $startedAt): PetImmunizationPlan
    {
        return DB::transaction(function () use ($pet, $protocol, $startedAt): PetImmunizationPlan {
            $plan = PetImmunizationPlan::create([
                'pet_id' => $pet->id,
                'protocol_id' => $protocol->id,
                'started_at' => $startedAt->toDateString(),
                'status' => PetImmunizationPlanStatus::ACTIVE->value,
            ]);

            $protocolDoses = $protocol->doses()->get();
            $scheduledDatesByProtocolDoseId = [];

            foreach ($this->orderByDependency($protocolDoses) as $protocolDose) {
                $scheduledFor = $this->initialScheduleFor($protocolDose, $startedAt, $scheduledDatesByProtocolDoseId);
                $scheduledDatesByProtocolDoseId[$protocolDose->id] = $scheduledFor;

                PetImmunizationDose::create([
                    'plan_id' => $plan->id,
                    'protocol_dose_id' => $protocolDose->id,
                    'scheduled_for' => $scheduledFor->toDateString(),
                ]);
            }

            return $plan->fresh('doses');
        });
    }

    /**
     * Marca a dose como aplicada e recalcula, em cascata, o `scheduled_for` de toda dose
     * dependente ancorada em `last_application` (regra 5). Doses ancoradas em
     * `first_application` mantêm a grade original — não são tocadas aqui.
     */
    public function applyDose(
        PetImmunizationDose $dose,
        User $appliedBy,
        CarbonInterface $appliedAt,
        ?int $vaccinationId,
    ): PetImmunizationDose {
        return DB::transaction(function () use ($dose, $appliedBy, $appliedAt, $vaccinationId): PetImmunizationDose {
            $dose->forceFill([
                'applied_at' => $appliedAt,
                'applied_by' => $appliedBy->id,
                'vaccination_id' => $vaccinationId,
            ])->save();

            $this->rescheduleDependents($dose);
            $this->completeIfFinished($dose->plan);

            return $dose->fresh();
        });
    }

    public function skipDose(PetImmunizationDose $dose): PetImmunizationDose
    {
        $dose->forceFill(['skipped' => true])->save();

        return $dose->fresh();
    }

    /**
     * Doses filhas (mesma `plan_id`) do protocolo-dose recém aplicado. Só a que ancora em
     * `last_application` é empurrada para a data real de aplicação — a recursão garante que
     * netos também sejam recalculados quando a cadeia inteira ancora em `last_application`.
     */
    private function rescheduleDependents(PetImmunizationDose $appliedDose): void
    {
        $childDoses = PetImmunizationDose::query()
            ->where('plan_id', $appliedDose->plan_id)
            ->whereHas('protocolDose', fn ($query) => $query->where('depends_on_dose_id', $appliedDose->protocol_dose_id))
            ->with('protocolDose')
            ->get();

        foreach ($childDoses as $childDose) {
            if ($childDose->protocolDose->anchor !== ImmunizationDoseAnchor::LAST_APPLICATION) {
                continue;
            }

            $newScheduledFor = $appliedDose->applied_at->copy()->addDays($childDose->protocolDose->interval_days ?? 0);
            $childDose->forceFill(['scheduled_for' => $newScheduledFor->toDateString()])->save();

            $this->rescheduleDependents($childDose);
        }
    }

    private function completeIfFinished(PetImmunizationPlan $plan): void
    {
        if ($plan->protocol->total_doses === null) {
            return;
        }

        $stillPending = $plan->doses()->pending()->exists();
        if (! $stillPending) {
            $plan->forceFill(['status' => PetImmunizationPlanStatus::COMPLETED->value])->save();
        }
    }

    /**
     * Dose sem dependência conta a partir de `started_at` do plano; dose dependente conta a
     * partir da data JÁ CALCULADA da dose-mãe (ainda não aplicada nenhuma delas neste ponto
     * — é a grade original do plano recém-criado).
     *
     * @param  array<int, CarbonInterface>  $scheduledDatesByProtocolDoseId
     */
    private function initialScheduleFor(
        ImmunizationProtocolDose $protocolDose,
        CarbonInterface $startedAt,
        array $scheduledDatesByProtocolDoseId,
    ): CarbonInterface {
        $intervalDays = $protocolDose->interval_days ?? 0;

        if ($protocolDose->depends_on_dose_id === null) {
            return $startedAt->copy()->addDays($intervalDays);
        }

        $parentDate = $scheduledDatesByProtocolDoseId[$protocolDose->depends_on_dose_id] ?? $startedAt;

        return $parentDate->copy()->addDays($intervalDays);
    }

    /**
     * Ordena as doses do protocolo para que uma dependente nunca seja processada antes da
     * dose de que ela depende — grafo pequeno (≤7 nós), ordenação topológica ingênua por
     * repetição é suficiente e mais legível que um algoritmo genérico aqui.
     *
     * @param  Collection<int, ImmunizationProtocolDose>  $protocolDoses
     * @return Collection<int, ImmunizationProtocolDose>
     */
    private function orderByDependency(Collection $protocolDoses): Collection
    {
        $ordered = collect();
        $remaining = $protocolDoses->values();

        while ($remaining->isNotEmpty()) {
            [$ready, $stillWaiting] = $remaining->partition(
                fn (ImmunizationProtocolDose $dose): bool => $dose->depends_on_dose_id === null
                    || $ordered->contains('id', $dose->depends_on_dose_id)
            );

            if ($ready->isEmpty()) {
                // Dependência quebrada/circular — processa o resto na ordem de dose_number
                // em vez de travar num loop infinito.
                return $ordered->merge($remaining->sortBy('dose_number'));
            }

            $ordered = $ordered->merge($ready);
            $remaining = $stillWaiting->values();
        }

        return $ordered;
    }
}
