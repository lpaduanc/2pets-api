<?php

namespace App\Services\Commercial\Package;

use App\Enums\SoldPackageSessionStatus;
use App\Models\Appointment;
use App\Models\OrganizationMember;
use App\Models\SoldPackage;
use App\Models\SoldPackageConsumption;
use App\Models\SoldPackageItem;
use App\Models\User;
use App\Services\Commercial\Reversal\CommissionReversal;
use App\Services\Commercial\SoldPackageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Baixa automática de sessão quando o agendamento marcado para um pacote
 * (`appointments.sold_package_id`) é concluído (PLANO 2.7), chamada pelo
 * `AppointmentCompletionObserver`.
 *
 * Qual serviço: o do agendamento que está no pacote (pivô `appointment_services` ou o
 * `service_id` legado); sem ele, o único serviço do pacote; ambíguo (nenhum ou mais de um
 * candidato), não baixa — fica a baixa manual. `staff_id` = vínculo do profissional do
 * agendamento na organização do pacote. Idempotente: uma baixa por agendamento (qualquer baixa
 * já ligada a ele, manual ou automática, conta).
 *
 * Nunca quebra a conclusão do atendimento: pacote sem saldo, vencido ou cancelado só gera log.
 * Saiu de `completed` ou foi excluído: `undo()` apaga as baixas ligadas ao agendamento, as
 * sessões voltam a `pending` e a comissão já fechada delas vira estorno (mecanismo da 2.3).
 */
final class AppointmentPackageConsumption
{
    public function __construct(
        private readonly SoldPackageService $packages,
        private readonly CommissionReversal $commissions,
    ) {}

    public function consume(Appointment $appointment): ?SoldPackageConsumption
    {
        $package = $appointment->sold_package_id === null ? null : SoldPackage::with(['items', 'servicePackage'])->find($appointment->sold_package_id);

        if ($package === null || $this->alreadyConsumed($appointment)) {
            return null;
        }

        $item = $this->itemFor($appointment, $package);

        if ($item === null || ! $package->canBeConsumed() || $item->quantityRemaining() < 1) {
            $this->skip($appointment, $package, $item === null ? 'serviço ambíguo ou fora do pacote' : 'pacote sem saldo, vencido ou cancelado');

            return null;
        }

        try {
            return $this->packages->consume($package, [
                'sold_package_item_id' => $item->id,
                'quantity' => 1,
                'appointment_id' => $appointment->id,
                'staff_id' => $this->staffId($appointment, $package),
                'notes' => 'Baixa automática ao concluir o agendamento',
            ], $this->actor($appointment));
        } catch (HttpException|ValidationException $e) {
            $this->skip($appointment, $package, $e->getMessage());

            return null;
        }
    }

    /** @return int quantas sessões voltaram a `pending` */
    public function undo(Appointment $appointment, string $reason): int
    {
        $consumptions = SoldPackageConsumption::query()->where('appointment_id', $appointment->id)->get();

        return DB::transaction(function () use ($consumptions, $reason): int {
            $restored = 0;

            foreach ($consumptions as $consumption) {
                $sessions = $consumption->sessions()->get();
                $this->commissions->reverseForSessions($sessions->modelKeys(), $reason, auth()->id());
                $consumption->sessions()->update([
                    'status' => SoldPackageSessionStatus::PENDING->value, 'sold_package_consumption_id' => null,
                    'consumed_at' => null, 'staff_id' => null, 'unit_cost' => null,
                ]);
                SoldPackageItem::whereKey($consumption->sold_package_item_id)->decrement('quantity_used', $consumption->quantity);
                $consumption->delete();
                $restored += $sessions->count();
            }

            return $restored;
        });
    }

    private function alreadyConsumed(Appointment $appointment): bool
    {
        return SoldPackageConsumption::query()->where('appointment_id', $appointment->id)->exists();
    }

    private function itemFor(Appointment $appointment, SoldPackage $package): ?SoldPackageItem
    {
        $serviceIds = $appointment->services()->pluck('service_id')->push($appointment->service_id)->filter()->unique();
        $matching = $package->items->whereIn('service_id', $serviceIds->all());

        if ($matching->count() === 1) {
            return $matching->first();
        }

        return $matching->isEmpty() && $package->items->count() === 1 ? $package->items->first() : null;
    }

    private function staffId(Appointment $appointment, SoldPackage $package): ?int
    {
        if ($package->organization_id === null) {
            return null;
        }

        return OrganizationMember::query()->where('organization_id', $package->organization_id)
            ->where('user_id', $appointment->professional_id)->where('is_active', true)->value('id');
    }

    private function actor(Appointment $appointment): User
    {
        return auth()->user() ?? User::findOrFail($appointment->professional_id);
    }

    private function skip(Appointment $appointment, SoldPackage $package, string $why): void
    {
        Log::info('Baixa automática de pacote não feita ao concluir o agendamento.', [
            'appointment_id' => $appointment->id, 'sold_package_id' => $package->id, 'motivo' => $why,
        ]);
    }
}
