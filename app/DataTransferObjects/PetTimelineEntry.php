<?php

namespace App\DataTransferObjects;

use Carbon\CarbonInterface;

/**
 * Uma linha da timeline agregada do pet (`PetTimelineService`). Encapsula os 3 tipos de
 * evento (atendimento finalizado, vacina, peso) atrás de um formato único, para que o merge
 * e a ordenação cronológica não precisem conhecer os models de origem.
 */
final readonly class PetTimelineEntry
{
    public function __construct(
        public string $type,
        public CarbonInterface $date,
        public int $id,
        public string $title,
        public ?string $summary,
        public ?string $professionalName,
    ) {}

    /**
     * @return array{type: string, date: string, id: int, title: string, summary: ?string, professional: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'date' => $this->date->toDateString(),
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'professional' => $this->professionalName,
        ];
    }
}
