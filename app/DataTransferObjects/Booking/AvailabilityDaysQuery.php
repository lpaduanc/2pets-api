<?php

namespace App\DataTransferObjects\Booking;

use Carbon\Carbon;

/**
 * Parâmetros de `GET /api/public/booking/availability-days` (Fase 2, item 5) — um mês
 * inteiro, para um profissional específico OU para a equipe de uma organização.
 */
final readonly class AvailabilityDaysQuery
{
    public function __construct(
        public ?int $professionalId,
        public ?int $organizationId,
        public ?int $locationId,
        public ?int $serviceId,
        public Carbon $month,
    ) {}
}
