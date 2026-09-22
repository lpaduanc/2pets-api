<?php

namespace App\DataTransferObjects\Reports;

use Carbon\CarbonInterface;

/**
 * Uma linha do feed unificado de eventos da clínica (`ClinicEventFeedService`) — contrato
 * `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`, regra de negócio 5: referencia
 * o registro de origem (`type`/`id`), nunca copia o conteúdo clínico para uma tabela própria.
 */
final readonly class ClinicEventFeedEntry
{
    public function __construct(
        public string $type,
        public CarbonInterface $date,
        public int $id,
        public string $title,
        public ?int $petId,
        public ?string $petName,
        public ?int $clientId,
        public ?string $clientName,
        public ?string $clientPhone,
        public ?string $clientEmail,
        public ?string $professionalName,
    ) {}

    /**
     * `phone`/`email` só entram quando `$includeContact` é verdadeiro (regra de negócio 4 da
     * spec) — o chamador decide isso a partir da permissão `clients.contact.view-bulk`, este
     * DTO só obedece.
     *
     * @return array{type: string, date: string, id: int, title: string, pet: array{id: ?int, name: ?string}, client: array<string, mixed>, professional: ?string}
     */
    public function toArray(bool $includeContact = false): array
    {
        $client = ['id' => $this->clientId, 'name' => $this->clientName];

        if ($includeContact) {
            $client += ['phone' => $this->clientPhone, 'email' => $this->clientEmail];
        }

        return [
            'type' => $this->type,
            'date' => $this->date->toIso8601String(),
            'id' => $this->id,
            'title' => $this->title,
            'pet' => ['id' => $this->petId, 'name' => $this->petName],
            'client' => $client,
            'professional' => $this->professionalName,
        ];
    }
}
