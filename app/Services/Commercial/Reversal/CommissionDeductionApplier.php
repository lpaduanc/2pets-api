<?php

namespace App\Services\Commercial\Reversal;

use App\Models\CommissionClawback;
use App\Models\CommissionSettlement;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * Desconta, num fechamento novo, a comissão estornada que o profissional ainda deve (PLANO 2.3).
 * Mesmo vínculo (`staff_id` é de uma organização), clawbacks mais antigos primeiro, até o bruto
 * do fechamento: o total nunca fica negativo, e o que não couber fica pendente para o próximo.
 * Roda dentro da transação do fechamento e TRAVA os clawbacks pendentes do vínculo — dois
 * fechamentos simultâneos não descontam o mesmo valor (e o `unique(settlement, clawback)` segura
 * a repetição no mesmo fechamento).
 */
final class CommissionDeductionApplier
{
    /** @return Money o total descontado */
    public function applyTo(CommissionSettlement $settlement, Money $gross): Money
    {
        $room = $gross;

        foreach ($this->lockedPending($settlement->staff_id) as $clawback) {
            if (! $room->isPositive()) {
                break;
            }

            $amount = $clawback->pendingAmount()->min($room);
            $settlement->deductions()->create(['commission_clawback_id' => $clawback->id, 'amount' => $amount->toString()]);
            $room = $room->minus($amount);
        }

        return $gross->minus($room);
    }

    /** @return Collection<int, CommissionClawback> */
    public function pendingFor(int $staffId): Collection
    {
        return CommissionClawback::query()->pending()->where('staff_id', $staffId)->with(['deductions', 'saleItem:id,sale_id,description'])->orderBy('id')->get();
    }

    /** @return Collection<int, CommissionClawback> */
    private function lockedPending(int $staffId): Collection
    {
        return CommissionClawback::query()->pending()->where('staff_id', $staffId)->orderBy('id')->lockForUpdate()->get()->load('deductions');
    }
}
