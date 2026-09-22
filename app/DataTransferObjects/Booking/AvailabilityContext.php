<?php

namespace App\DataTransferObjects\Booking;

/**
 * "Em qual local/organização estamos consultando a agenda" — Fase 2 do fluxo de
 * agendamento. `locationId` tem prioridade sobre `organizationId` quando os dois vêm
 * preenchidos (um local pertence a uma organização, mas é mais específico que ela).
 * Ambos `null` preserva o comportamento de antes da Fase 2: agenda "solta" do profissional,
 * sem filtro de estabelecimento.
 */
final readonly class AvailabilityContext
{
    public function __construct(
        public ?int $organizationId = null,
        public ?int $locationId = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->organizationId === null && $this->locationId === null;
    }
}
