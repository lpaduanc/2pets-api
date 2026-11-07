<?php

namespace App\Services\Transport;

use App\Enums\InvoiceStatus;
use App\Enums\PetTransportStatus;
use App\Enums\PetTransportType;
use App\Models\Appointment;
use App\Models\PetTransport;
use App\Models\Service;
use App\Services\Invoice\AppointmentInvoiceService;
use App\Support\BusinessClock;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leva e traz no agendamento (PLANO 3.7).
 *
 * A TAXA vira item de venda no MESMO caminho de qualquer serviço do agendamento: uma linha do
 * serviço "Leva e traz" (criado sob demanda, `services.system_key = pet_transport`) em
 * `appointment_services`, com o preço congelado na hora do agendamento. Dali ela segue para a
 * fatura do atendimento (que nasce ao iniciar o atendimento, `AppointmentInvoiceService`) e,
 * paga, para receita, caixa e DRE — sem caminho financeiro paralelo. Por isso o item nasce no
 * AGENDAMENTO (entra na estimativa de preço e no orçamento) e é cobrado na conclusão, como os
 * outros serviços.
 *
 * Cancelar o transporte tira a linha enquanto a fatura não foi paga (fatura pendente é
 * recalculada). Fatura paga não é tocada: estorno segue o fluxo da fatura. Falha mantém a taxa
 * (o deslocamento aconteceu); a clínica ajusta na fatura se quiser.
 */
final class PetTransportService
{
    public const SERVICE_KEY = 'pet_transport';

    public const SERVICE_NAME = 'Leva e traz';

    public function __construct(
        private readonly TransportQuoteService $quotes,
        private readonly AppointmentInvoiceService $invoices,
    ) {}

    /**
     * Zona e taxa vêm da organização ATIVA de quem agenda (`$organizationId`): o agendamento
     * avulso não grava `organization_id`, mas a zona é cadastro da organização.
     *
     * @param  array{type: string, address: array<string, string|null>}  $transport
     */
    public function attach(Appointment $appointment, array $transport, ?int $organizationId): PetTransport
    {
        return DB::transaction(function () use ($appointment, $transport, $organizationId): PetTransport {
            $address = $transport['address'];
            $quote = $this->quotes->quote($organizationId, $appointment->professional_id, $address);
            $line = $appointment->services()->create([
                'service_id' => $this->service($organizationId, $appointment->professional_id)->id,
                'quantity' => 1,
                'unit_price' => $quote['fee'],
            ]);
            $appointment->update(['price' => Money::of($appointment->price ?? 0)->plus($quote['fee'])->toString()]);

            return PetTransport::create([
                'organization_id' => $organizationId,
                'professional_id' => $appointment->professional_id,
                'appointment_id' => $appointment->id,
                'service_area_id' => $quote['zone']->id,
                'appointment_service_id' => $line->id,
                'type' => PetTransportType::from($transport['type']),
                'zip_code' => $address['zip_code'] ?? null,
                'address' => $address['address'] ?? null,
                'complement' => $address['complement'] ?? null,
                'city' => $address['city'] ?? null,
                'state' => isset($address['state']) ? mb_strtoupper($address['state']) : null,
                'distance_km' => $quote['distance_km'],
                'fee' => $quote['fee'],
                'status' => PetTransportStatus::PENDING,
                'scheduled_pickup_at' => $this->scheduledAt($appointment),
            ]);
        });
    }

    public function changeStatus(PetTransport $transport, PetTransportStatus $target, ?int $driverId = null): PetTransport
    {
        if (! in_array($target, $transport->status->allowedNext(), true)) {
            throw ValidationException::withMessages(['status' => sprintf('Não dá para ir de "%s" para "%s".', $transport->status->label(), $target->label())]);
        }

        return DB::transaction(function () use ($transport, $target, $driverId): PetTransport {
            $transport->update(array_filter([
                'status' => $target,
                $target->timestampColumn() => now(),
                'driver_id' => $driverId,
            ], fn ($value): bool => $value !== null));

            if ($target === PetTransportStatus::CANCELLED) {
                $this->dropCharge($transport);
            }

            return $transport->fresh(PetTransport::RESOURCE_RELATIONS);
        });
    }

    /** Linha do serviço "Leva e traz" sai enquanto a fatura do atendimento não foi paga. */
    private function dropCharge(PetTransport $transport): void
    {
        $appointment = $transport->appointment()->with('invoice')->first();
        $line = $transport->chargeLine;
        $invoice = $appointment?->invoice;

        if ($line === null || ($invoice !== null && $invoice->status !== InvoiceStatus::PENDING)) {
            return;
        }

        $line->delete();
        $appointment->update(['price' => Money::of($appointment->price ?? 0)->minus($transport->fee)->max(Money::zero())->toString()]);

        if ($invoice !== null) {
            $this->invoices->syncItems($appointment);
        }
    }

    private function service(?int $organizationId, int $professionalId): Service
    {
        $owner = ['organization_id' => $organizationId, 'professional_id' => $professionalId];
        $query = Service::query()->where('system_key', self::SERVICE_KEY)
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId), fn ($q) => $q->whereNull('organization_id')->where('professional_id', $professionalId));

        $existing = $query->first();

        if ($existing !== null) {
            return $existing;
        }

        $service = Service::create($owner + [
            'name' => self::SERVICE_NAME,
            'category' => 'other',
            'price' => 0,
            'duration' => 0,
            'active' => true,
            'show_in_price_list' => false,
            'allow_price_override' => true,
            'commission_percent' => 0,
        ]);
        // `system_key` fora do fillable: só o sistema marca o serviço dele.
        $service->forceFill(['system_key' => self::SERVICE_KEY])->save();

        return $service;
    }

    /** Data + hora do agendamento (calendário de São Paulo) → instante UTC. */
    private function scheduledAt(Appointment $appointment): ?CarbonImmutable
    {
        if ($appointment->appointment_date === null) {
            return null;
        }

        $time = $appointment->appointment_time?->format('H:i') ?? '00:00';

        return CarbonImmutable::parse($appointment->appointment_date->format('Y-m-d').' '.$time, BusinessClock::timezone())->utc();
    }
}
