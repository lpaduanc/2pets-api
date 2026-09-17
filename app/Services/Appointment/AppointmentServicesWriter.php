<?php

namespace App\Services\Appointment;

use App\Models\Appointment;
use App\Models\Service;

/**
 * Grava a pivô `appointment_services` a partir do payload `services[]` — contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.2/§13.7. Um
 * agendamento passa a poder ter vários serviços ao mesmo tempo (consulta + vacina +
 * banho); `appointments.service_id` (FK única) fica deprecada, não removida.
 *
 * `unit_price` é sempre um SNAPSHOT (invariante 12): quando o payload não informa, vem
 * do catálogo NO MOMENTO da escrita, e nunca mais muda se o preço do serviço subir depois.
 */
final class AppointmentServicesWriter
{
    /**
     * Substitui todas as linhas do agendamento pelas do payload (idempotente em edição,
     * via soft delete) e devolve o total, para o controller gravar em
     * `appointments.price` como estimativa pré-atendimento.
     *
     * @param  array<int, array{service_id: int, quantity?: ?float, unit_price?: ?float}>  $services
     */
    public function sync(Appointment $appointment, array $services): float
    {
        $appointment->services()->delete();

        return round(array_sum(array_map(
            fn (array $line): float => $this->createLine($appointment, $line),
            $services,
        )), 2);
    }

    private function createLine(Appointment $appointment, array $line): float
    {
        $service = Service::where('professional_id', $appointment->professional_id)->findOrFail($line['service_id']);
        $quantity = (float) ($line['quantity'] ?? 1);
        $unitPrice = (float) ($line['unit_price'] ?? $service->price);

        $appointment->services()->create([
            'service_id' => $service->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]);

        return $quantity * $unitPrice;
    }
}
