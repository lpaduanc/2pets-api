<?php

namespace App\Services\Dashboard\ActivityFeed;

use App\DataTransferObjects\TutorActivityItem;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Feed de "o que mudou nos meus pets e na minha conta", lido do `activity_log` (spatie/
 * laravel-activitylog) — cobre o cadastro do pet e seus registros de saúde (vacina,
 * vermífugo, medicação, cirurgia, exame, internação), alterados pelo próprio tutor OU por um
 * veterinário com acesso concedido, além do próprio cadastro do tutor. A formatação de cada
 * linha em `TutorActivityItem` mora em `TutorRecordActivityFormatter` — esta classe só resolve
 * o escopo (quais linhas de `activity_log` pertencem a este tutor).
 *
 * Visibilidade: mesma regra de `AuthorizesPetAccess::resolvePetAuditVisibility()` para o
 * DONO do pet — vê toda entrada, de qualquer causador. Este feed só cobre pets do próprio
 * tutor (nunca de outro), então não há caso de restringir por causer aqui.
 */
final class TutorRecordActivitySource
{
    public function __construct(private readonly TutorRecordActivityFormatter $formatter) {}

    /**
     * @return list<TutorActivityItem>
     */
    public function fetch(User $tutor, int $limit): array
    {
        $petNames = Pet::query()->where('user_id', $tutor->id)->pluck('name', 'id');
        $healthRecordPets = $this->mapHealthRecordsToPets($petNames->keys()->all());
        $activities = $this->queryActivities($tutor, $petNames->keys()->all(), $healthRecordPets, $limit);

        return $activities
            ->map(fn (Activity $activity) => $this->formatter->format($activity, $petNames, $healthRecordPets))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $petIds
     * @return array<class-string, array<int, int>>
     */
    private function mapHealthRecordsToPets(array $petIds): array
    {
        $map = [];
        foreach (TutorRecordActivityFormatter::healthResourceClasses() as $class) {
            $map[$class] = $this->recordIdsToPetId($class, $petIds);
        }

        return $map;
    }

    /**
     * @param  class-string  $class
     * @param  list<int>  $petIds
     * @return array<int, int>
     */
    private function recordIdsToPetId(string $class, array $petIds): array
    {
        if ($petIds === []) {
            return [];
        }

        return $class::withTrashed()->whereIn('pet_id', $petIds)->pluck('pet_id', 'id')->all();
    }

    /**
     * @param  list<int>  $petIds
     * @param  array<class-string, array<int, int>>  $healthRecordPets
     */
    private function queryActivities(User $tutor, array $petIds, array $healthRecordPets, int $limit): Collection
    {
        return Activity::query()
            ->where(fn ($outer) => $this->applyScope($outer, $tutor, $petIds, $healthRecordPets))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<int>  $petIds
     * @param  array<class-string, array<int, int>>  $healthRecordPets
     */
    private function applyScope(QueryBuilder $query, User $tutor, array $petIds, array $healthRecordPets): void
    {
        $query->where(fn ($q) => $q->where('subject_type', User::class)->where('subject_id', $tutor->id));
        $query->orWhere(fn ($q) => $q->where('subject_type', Pet::class)->whereIn('subject_id', $petIds));

        foreach ($healthRecordPets as $class => $idToPet) {
            $this->addHealthClauseIfAny($query, $class, $idToPet);
        }
    }

    /**
     * @param  class-string  $class
     * @param  array<int, int>  $idToPet
     */
    private function addHealthClauseIfAny(QueryBuilder $query, string $class, array $idToPet): void
    {
        if ($idToPet === []) {
            return;
        }

        $query->orWhere(fn ($q) => $q->where('subject_type', $class)->whereIn('subject_id', array_keys($idToPet)));
    }
}
