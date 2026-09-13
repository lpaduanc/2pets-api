<?php

namespace App\Services\Dashboard;

use App\DataTransferObjects\DashboardDateWindow;
use App\Models\Appointment;
use App\Models\Invoice;
use Illuminate\Support\Collection;

/**
 * Lista de consultas de hoje e feed de atividade recente do dashboard do profissional —
 * extraído de `ProfessionalDashboardStatsService` para manter aquele serviço dentro do limite
 * de 200 linhas do projeto: é formatação de exibição (agenda do dia, feed de eventos), não
 * contagem/regra de negócio de estatística.
 */
final class ProfessionalDashboardActivityService
{
    /**
     * @param  list<int>  $professionalIds
     */
    public function todayAppointments(array $professionalIds, DashboardDateWindow $window): Collection
    {
        return Appointment::whereIn('professional_id', $professionalIds)
            ->where('appointment_date', '>=', $window->today)
            ->where('appointment_date', '<', $window->tomorrow)
            ->where('status', '!=', 'cancelled')
            ->with(['client:id,name', 'pet:id,name'])
            ->orderBy('appointment_time')
            ->get()
            ->map(fn (Appointment $appointment): array => $this->mapAppointmentDetail($appointment))
            ->values();
    }

    /**
     * @param  list<int>  $professionalIds
     */
    public function recentActivity(array $professionalIds): array
    {
        return array_merge(
            $this->recentAppointmentActivity($professionalIds),
            $this->recentPaymentActivity($professionalIds),
        );
    }

    private function mapAppointmentDetail(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'time' => $appointment->appointment_time ? substr($appointment->appointment_time, 0, 5) : '',
            'duration' => $appointment->duration ?? 30,
            'clientName' => $appointment->client?->name ?? 'Cliente',
            'petName' => $appointment->pet?->name ?? '',
            'service' => $appointment->service_name ?? $appointment->service ?? 'Consulta',
            'status' => $appointment->status,
        ];
    }

    /**
     * @param  list<int>  $professionalIds
     */
    private function recentAppointmentActivity(array $professionalIds): array
    {
        return Appointment::whereIn('professional_id', $professionalIds)
            ->with(['client:id,name', 'pet:id,name'])
            ->orderByDesc('updated_at')
            ->limit(3)
            ->get()
            ->map(fn (Appointment $appointment): array => [
                'id' => $appointment->id,
                'type' => 'appointment',
                'text' => ($appointment->service_name ?? 'Consulta').' - '.($appointment->pet?->name ?? '').' ('.($appointment->client?->name ?? '').')',
                'time' => $appointment->updated_at->diffForHumans(),
            ])
            ->all();
    }

    /**
     * @param  list<int>  $professionalIds
     */
    private function recentPaymentActivity(array $professionalIds): array
    {
        return Invoice::whereIn('professional_id', $professionalIds)
            ->where('status', 'paid')
            ->orderByDesc('updated_at')
            ->limit(2)
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'type' => 'payment',
                'text' => 'Pagamento recebido - R$ '.number_format((float) $invoice->total, 2, ',', '.'),
                'time' => $invoice->updated_at->diffForHumans(),
            ])
            ->all();
    }
}
