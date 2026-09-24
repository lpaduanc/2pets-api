<?php

namespace App\Services\Dashboard\ActivityFeed;

use App\DataTransferObjects\TutorActivityItem;
use App\Enums\ActivityFeedTone;
use App\Enums\ActivityFeedType;
use App\Models\Exam;
use App\Models\Hospitalization;
use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\PetMedication;
use App\Models\Surgery;
use App\Models\User;
use App\Models\Vaccination;
use App\Notifications\Support\FrontendRoute;
use App\Services\Audit\AuditDiffFormatter;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Traduz uma linha de `activity_log` (conta do tutor, cadastro de pet ou registro de saúde de
 * um dos seus pets) para um `TutorActivityItem` legível — extraído de
 * `TutorRecordActivitySource` para manter aquela classe (consulta/escopo) dentro do limite de
 * 200 linhas do projeto: formatação de exibição é uma responsabilidade separada de montar a
 * query.
 */
final class TutorRecordActivityFormatter
{
    /**
     * @var array<class-string, array{label: string, created: string, updated: string, deleted: string, icon: string}>
     */
    private const HEALTH_RESOURCES = [
        Vaccination::class => ['label' => 'Vacina', 'created' => 'Vacina registrada', 'updated' => 'Vacina atualizada', 'deleted' => 'Vacina removida', 'icon' => 'vaccines'],
        PetDeworming::class => ['label' => 'Vermífugo', 'created' => 'Vermífugo registrado', 'updated' => 'Vermífugo atualizado', 'deleted' => 'Vermífugo removido', 'icon' => 'medication'],
        PetMedication::class => ['label' => 'Medicação', 'created' => 'Medicação registrada', 'updated' => 'Medicação atualizada', 'deleted' => 'Medicação removida', 'icon' => 'medication'],
        Surgery::class => ['label' => 'Cirurgia', 'created' => 'Cirurgia registrada', 'updated' => 'Cirurgia atualizada', 'deleted' => 'Cirurgia removida', 'icon' => 'medical_services'],
        Exam::class => ['label' => 'Exame', 'created' => 'Exame registrado', 'updated' => 'Exame atualizado', 'deleted' => 'Exame removido', 'icon' => 'biotech'],
        Hospitalization::class => ['label' => 'Internação', 'created' => 'Internação registrada', 'updated' => 'Internação atualizada', 'deleted' => 'Internação removida', 'icon' => 'local_hospital'],
    ];

    public function __construct(private readonly AuditDiffFormatter $diffFormatter) {}

    /**
     * @param  Collection<int, string>  $petNames
     * @param  array<class-string, array<int, int>>  $healthRecordPets
     */
    public function format(Activity $activity, Collection $petNames, array $healthRecordPets): ?TutorActivityItem
    {
        return match ($activity->subject_type) {
            User::class => $this->accountItem($activity),
            Pet::class => $this->petItem($activity, $petNames),
            default => $this->healthItem($activity, $petNames, $healthRecordPets),
        };
    }

    private function accountItem(Activity $activity): TutorActivityItem
    {
        $changedLabels = $this->changedFieldLabels($activity);

        return new TutorActivityItem(
            id: "activity:{$activity->id}",
            type: ActivityFeedType::ACCOUNT,
            icon: 'person',
            tone: ActivityFeedTone::INFO,
            title: 'Dados da conta atualizados',
            description: $changedLabels === [] ? null : 'Campos alterados: '.implode(', ', $changedLabels),
            petName: null,
            link: '/tutor/profile',
            createdAt: $activity->created_at,
        );
    }

    /**
     * @param  Collection<int, string>  $petNames
     */
    private function petItem(Activity $activity, Collection $petNames): TutorActivityItem
    {
        $petId = (int) $activity->subject_id;
        $petName = $petNames->get($petId, 'Pet');

        return new TutorActivityItem(
            id: "activity:{$activity->id}",
            type: ActivityFeedType::PET,
            icon: 'pets',
            tone: ActivityFeedTone::PRIMARY,
            title: $this->petTitle($activity->event),
            description: $this->petDescription($activity, $petName),
            petName: $petName,
            link: FrontendRoute::tutorPetOverview($petId),
            createdAt: $activity->created_at,
        );
    }

    private function petTitle(?string $event): string
    {
        return match ($event) {
            'created' => 'Pet cadastrado',
            'deleted' => 'Pet removido',
            default => 'Dados atualizados',
        };
    }

    private function petDescription(Activity $activity, string $petName): ?string
    {
        if ($activity->event === 'created') {
            return "{$petName} foi cadastrado(a) na plataforma.";
        }

        if ($activity->event === 'deleted') {
            return "{$petName} foi removido(a) da plataforma.";
        }

        $changedLabels = $this->changedFieldLabels($activity);
        if ($changedLabels === []) {
            return null;
        }

        $fields = implode(', ', array_slice($changedLabels, 0, 3));
        $extra = count($changedLabels) - 3;

        return "Dados de {$petName} atualizados: {$fields}".($extra > 0 ? " e mais {$extra} campo(s)" : '');
    }

    /**
     * @param  Collection<int, string>  $petNames
     * @param  array<class-string, array<int, int>>  $healthRecordPets
     */
    private function healthItem(Activity $activity, Collection $petNames, array $healthRecordPets): ?TutorActivityItem
    {
        $meta = self::HEALTH_RESOURCES[$activity->subject_type] ?? null;
        if ($meta === null) {
            return null;
        }

        $petId = $healthRecordPets[$activity->subject_type][$activity->subject_id] ?? null;
        $petName = $petId !== null ? $petNames->get($petId) : null;

        return new TutorActivityItem(
            id: "activity:{$activity->id}",
            type: ActivityFeedType::HEALTH,
            icon: $meta['icon'],
            tone: ActivityFeedTone::INFO,
            title: $meta[$activity->event] ?? $meta['updated'],
            description: $petName === null ? null : "Registro de {$meta['label']} de {$petName}.",
            petName: $petName,
            link: $petId === null ? null : FrontendRoute::tutorPetHealth($petId),
            createdAt: $activity->created_at,
        );
    }

    /**
     * @return list<string>
     */
    private function changedFieldLabels(Activity $activity): array
    {
        $props = $activity->properties?->toArray() ?? [];
        $diff = $this->diffFormatter->diff($props['old'] ?? null, $props['attributes'] ?? null);

        return collect($diff)->pluck('label')->map(fn (string $label) => mb_strtolower($label))->all();
    }

    /**
     * @return list<class-string>
     */
    public static function healthResourceClasses(): array
    {
        return array_keys(self::HEALTH_RESOURCES);
    }
}
