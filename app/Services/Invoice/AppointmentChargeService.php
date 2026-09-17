<?php

namespace App\Services\Invoice;

use App\Enums\AppointmentStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\Invoice\AppointmentChargesLockedException;
use App\Models\Appointment;
use App\Models\AppointmentCharge;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;

/**
 * Regra de negócio das linhas de cobrança lançadas durante o atendimento — contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.3/§13.5. Pendurada
 * no AGENDAMENTO, não mais no prontuário: banho e tosa lança igual a uma consulta.
 *
 * Toda mutação sincroniza a fatura em aberto (`AppointmentInvoiceService::syncItems`) —
 * o "chegou pra consulta, saiu com vacina" só vira cobrança real se a fatura já
 * `pending` acompanhar cada linha lançada, e não só o que existia no início.
 */
final class AppointmentChargeService
{
    public function __construct(private readonly AppointmentInvoiceService $invoiceService) {}

    /**
     * @param  array{service_id?: ?int, description?: ?string, quantity?: ?float, unit_price?: ?float, reference_date?: ?string}  $data
     */
    public function create(Appointment $appointment, User $addedBy, array $data): AppointmentCharge
    {
        $this->assertMutable($appointment);

        $service = $this->resolveService($appointment, $data['service_id'] ?? null);

        $charge = AppointmentCharge::create([
            'appointment_id' => $appointment->id,
            'service_id' => $service?->id,
            'description' => $data['description'] ?? $service?->name,
            'quantity' => $data['quantity'] ?? 1,
            'unit_price' => $data['unit_price'] ?? $service?->price,
            'added_by' => $addedBy->id,
            // Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md
            // §2.1: só a diária de internação envia isto — o resto do sistema continua sem
            // data própria (`created_at` basta).
            'reference_date' => $data['reference_date'] ?? null,
        ]);

        $this->invoiceService->syncItems($appointment);

        return $charge;
    }

    /**
     * @param  array{service_id?: ?int, description?: ?string, quantity?: ?float, unit_price?: ?float}  $data
     */
    public function update(Appointment $appointment, int $chargeId, array $data): AppointmentCharge
    {
        $this->assertMutable($appointment);

        $charge = $appointment->charges()->findOrFail($chargeId);

        if (array_key_exists('service_id', $data)) {
            $data['service_id'] = $this->resolveService($appointment, $data['service_id'])?->id;
        }

        $charge->update($data);
        $this->invoiceService->syncItems($appointment);

        return $charge;
    }

    public function delete(Appointment $appointment, int $chargeId): void
    {
        $this->assertMutable($appointment);

        $appointment->charges()->findOrFail($chargeId)->delete();
        $this->invoiceService->syncItems($appointment);
    }

    /**
     * Contrato §13.3: a comanda só aceita alteração enquanto o atendimento está EM
     * ANDAMENTO e a fatura (se já existir) não foi paga/cancelada — itens congelam no
     * pagamento (invariante 13), não na emissão.
     */
    private function assertMutable(Appointment $appointment): void
    {
        if (AppointmentStatus::from($appointment->status) !== AppointmentStatus::IN_PROGRESS) {
            throw AppointmentChargesLockedException::forInactiveAppointment();
        }

        $invoice = Invoice::where('appointment_id', $appointment->id)->first();

        if ($invoice !== null && ! $invoice->isPending()) {
            throw AppointmentChargesLockedException::forInvoiceStatus(InvoiceStatus::from($invoice->status));
        }
    }

    /**
     * Serviço precisa pertencer ao catálogo do PRÓPRIO PROFISSIONAL do agendamento —
     * quem lança a linha pode ser um colega (front desk) operando pela clínica, mas o
     * preço/nome vem sempre do catálogo de quem atende, nunca de um catálogo de terceiro.
     */
    private function resolveService(Appointment $appointment, ?int $serviceId): ?Service
    {
        if ($serviceId === null) {
            return null;
        }

        return Service::where('professional_id', $appointment->professional_id)->findOrFail($serviceId);
    }
}
