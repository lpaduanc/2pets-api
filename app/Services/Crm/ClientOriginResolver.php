<?php

namespace App\Services\Crm;

use App\Enums\BookingSource;
use App\Models\Appointment;
use App\Models\ClientOrigin;

/**
 * Origem automática vs. manual — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`, regra de negócio 5. Só preenche
 * quando o PRIMEIRO agendamento do cliente com a equipe nasceu de `BookingSource::CLIENT`
 * (busca pública pelo próprio tutor — "tutor" no texto da spec é este valor do enum). Qualquer
 * outro caso fica `null` até a clínica preencher manualmente — nunca adivinha
 * "Indicação de amigo" ou parecido.
 */
final class ClientOriginResolver
{
    /**
     * @param  list<int>  $teamUserIds
     */
    public function resolveAutomaticOriginId(array $teamUserIds, int $clientId): ?int
    {
        if (! $this->firstAppointmentCameFromPublicSearch($teamUserIds, $clientId)) {
            return null;
        }

        return ClientOrigin::query()
            ->whereNull('organization_id')
            ->whereRaw('lower(name) = ?', [mb_strtolower(ClientOrigin::AUTOMATIC_SEARCH_ORIGIN_NAME)])
            ->value('id');
    }

    /** @param  list<int>  $teamUserIds */
    private function firstAppointmentCameFromPublicSearch(array $teamUserIds, int $clientId): bool
    {
        $firstAppointment = Appointment::query()
            ->whereIn('professional_id', $teamUserIds)
            ->where('client_id', $clientId)
            ->orderBy('appointment_date')
            ->first(['booking_source']);

        return $firstAppointment?->booking_source === BookingSource::CLIENT->value;
    }
}
