<?php

namespace App\Services\Audit;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\Activitylog\Models\Activity;

/**
 * Feed de auditoria genérico por registro — item 22 do backlog gap-simplesvet
 * (`GET {resource}/{id}/activity`), extraído de `PetAuditController` (o único lugar
 * que já implementava isto) para ser reaproveitado por outros recursos sem duplicar a
 * lógica de consulta no `activity_log` polimórfico do spatie/laravel-activitylog.
 *
 * A autorização (quem pode ver o log de qual registro) e a decisão de restringir a
 * visão a "só as próprias entradas" continuam no controller/Policy de cada recurso —
 * este serviço só sabe montar e formatar a consulta.
 */
class ActivityFeedService
{
    public function __construct(private readonly AuditDiffFormatter $auditDiffFormatter) {}

    /**
     * @param  class-string  $subjectClass
     * @param  array<class-string, list<int>>  $childSubjectIds  IDs de registros filhos por classe, cujas
     *                                                           entradas de log também entram no feed.
     */
    public function paginate(
        string $subjectClass,
        int $subjectId,
        array $childSubjectIds,
        ?int $restrictToCauserId,
        int $perPage
    ): LengthAwarePaginator {
        $query = Activity::query()
            ->where(function ($outer) use ($subjectClass, $subjectId, $childSubjectIds): void {
                $outer->where(fn ($inner) => $inner->where('subject_type', $subjectClass)->where('subject_id', $subjectId));

                foreach ($childSubjectIds as $class => $ids) {
                    if (empty($ids)) {
                        continue;
                    }

                    $outer->orWhere(fn ($inner) => $inner->where('subject_type', $class)->whereIn('subject_id', $ids));
                }
            })
            ->with('causer:id,name,role')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($restrictToCauserId !== null) {
            $query->where('causer_id', $restrictToCauserId)->where('causer_type', User::class);
        }

        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn (Activity $activity): array => $this->format($activity));

        return $page;
    }

    /**
     * IDs de registros filhos de um dono, por classe — inclui soft-deleted, porque um
     * registro apagado ainda tem história de auditoria a contar.
     *
     * @param  list<class-string>  $childClasses
     * @return array<class-string, list<int>>
     */
    public function collectChildSubjectIds(array $childClasses, string $foreignKey, int $ownerId): array
    {
        $out = [];
        foreach ($childClasses as $class) {
            $query = $class::query()->where($foreignKey, $ownerId);
            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($class), true)) {
                $query->withTrashed();
            }
            $out[$class] = $query->pluck('id')->all();
        }

        return $out;
    }

    /**
     * @return array{
     *     id: int, event: string|null, log_name: string|null, subject_type: string|null,
     *     subject_id: int|null, description: string|null,
     *     properties: array{old: mixed, new: mixed, change_reason: mixed, semantic_event: mixed},
     *     diff: list<array{field: string, label: string, old: string|null, new: string|null, text: string}>,
     *     causer: array{id: int, name: string, role: mixed}|null, created_at: string|null,
     * }
     */
    private function format(Activity $activity): array
    {
        $subjectType = $activity->subject_type ? class_basename($activity->subject_type) : null;

        $causer = null;
        if ($activity->causer) {
            $causer = [
                'id' => $activity->causer->id,
                'name' => $activity->causer->name,
                'role' => $activity->causer->role,
            ];
        }

        $props = $activity->properties?->toArray() ?? [];
        $old = $props['old'] ?? null;
        $new = $props['attributes'] ?? ($props['new'] ?? null);

        return [
            'id' => $activity->id,
            'event' => $activity->event,
            'log_name' => $activity->log_name,
            'subject_type' => $subjectType,
            'subject_id' => $activity->subject_id,
            'description' => $activity->description,
            'properties' => [
                'old' => $old,
                'new' => $new,
                'change_reason' => $props['change_reason'] ?? null,
                'semantic_event' => $props['semantic_event'] ?? null,
            ],
            'diff' => $this->auditDiffFormatter->diff($old, $new),
            'causer' => $causer,
            'created_at' => $activity->created_at?->toISOString(),
        ];
    }
}
