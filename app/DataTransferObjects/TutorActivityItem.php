<?php

namespace App\DataTransferObjects;

use App\Enums\ActivityFeedTone;
use App\Enums\ActivityFeedType;
use Carbon\CarbonInterface;

/**
 * Um item do feed "Atividade Recente" da Início do tutor (`GET /api/dashboard/stats`,
 * `data.recentActivity[]`). Cada fonte (`app/Services/Dashboard/ActivityFeed/*`) produz uma
 * lista destes, e `TutorActivityFeedService` só ordena por `createdAt` e corta no limite —
 * nenhuma fonte precisa conhecer o formato final de resposta HTTP.
 */
final readonly class TutorActivityItem
{
    public function __construct(
        public string $id,
        public ActivityFeedType $type,
        public string $icon,
        public ActivityFeedTone $tone,
        public string $title,
        public ?string $description,
        public ?string $petName,
        public ?string $link,
        public CarbonInterface $createdAt,
    ) {}

    /**
     * @return array{id: string, type: string, icon: string, tone: string, title: string,
     *     description: string|null, pet_name: string|null, link: string|null, time: string,
     *     created_at: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'icon' => $this->icon,
            'tone' => $this->tone->value,
            'title' => $this->title,
            'description' => $this->description,
            'pet_name' => $this->petName,
            'link' => $this->link,
            'time' => $this->createdAt->diffForHumans(),
            'created_at' => $this->createdAt->toISOString(),
        ];
    }
}
