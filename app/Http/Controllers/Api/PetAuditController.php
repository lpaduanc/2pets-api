<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Hospitalization;
use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\PetMedication;
use App\Models\Surgery;
use App\Models\Vaccination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * GET /pets/{pet}/audit — chronological activity feed for a pet and its clinical records.
 *
 * Visibility matrix:
 *   - Pet owner (tutor)  → every change ever made to the pet and its health records.
 *   - Admin              → same as owner.
 *   - Vet with active grant → only the entries where `causer_id = their own user_id`.
 *   - Anyone else        → 403.
 *
 * Implementation note: Spatie's activity_log is a single polymorphic table. We
 * query it once with subject_type/id predicates that match the pet itself plus
 * all the known child resource types.
 */
class PetAuditController extends Controller
{
    use AuthorizesPetAccess;

    /**
     * Every model class whose activity logs should surface in the pet's timeline.
     * Kept as a constant so adding a new clinical resource is a one-liner.
     */
    private const CHILD_SUBJECT_CLASSES = [
        Vaccination::class,
        PetDeworming::class,
        PetMedication::class,
        Surgery::class,
        Exam::class,
        Hospitalization::class,
    ];

    public function index(Request $request, int $petId): JsonResponse
    {
        $pet = Pet::findOrFail($petId);
        $user = $request->user();

        $isOwner = $this->isPetOwner($user, $pet);
        $isAdmin = $user?->hasAnyRole(['admin', 'super_admin']) || $user?->role === 'admin';
        $hasActiveVetGrant = $user ? $this->hasActiveVetAccess($user->id, $pet->id) : false;

        if (! $isOwner && ! $isAdmin && ! $hasActiveVetGrant) {
            abort(403, 'Você não tem permissão para ver a auditoria deste pet.');
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));

        // Find child record IDs tied to this pet so we can filter activity_log
        // entries that target them. We collect soft-deleted rows too — a deleted
        // record still has an audit story to tell.
        $childSubjectIds = $this->collectChildSubjectIds($petId);

        $query = Activity::query()
            ->where(function ($q) use ($pet, $childSubjectIds) {
                // Logs on the pet itself.
                $q->where(function ($inner) use ($pet) {
                    $inner->where('subject_type', Pet::class)
                        ->where('subject_id', $pet->id);
                });

                // Logs on the child clinical records.
                foreach ($childSubjectIds as $class => $ids) {
                    if (empty($ids)) {
                        continue;
                    }
                    $q->orWhere(function ($inner) use ($class, $ids) {
                        $inner->where('subject_type', $class)
                            ->whereIn('subject_id', $ids);
                    });
                }
            })
            ->with('causer:id,name,role')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // Vet grant (without owner/admin): restrict to entries they authored.
        if (! $isOwner && ! $isAdmin) {
            $query->where('causer_id', $user->id)
                ->where('causer_type', \App\Models\User::class);
        }

        $page = $query->paginate($perPage);

        $page->getCollection()->transform(fn (Activity $a) => $this->formatActivity($a));

        return response()->json($page);
    }

    /**
     * Shape each activity entry into a frontend-friendly row. Namespace-less
     * subject_type, causer projection, property bag preserved.
     */
    private function formatActivity(Activity $activity): array
    {
        $subjectType = $activity->subject_type
            ? class_basename($activity->subject_type)
            : null;

        $causer = null;
        if ($activity->causer) {
            $causer = [
                'id' => $activity->causer->id,
                'name' => $activity->causer->name,
                'role' => $activity->causer->role,
            ];
        }

        $props = $activity->properties?->toArray() ?? [];

        return [
            'id' => $activity->id,
            'event' => $activity->event,
            'log_name' => $activity->log_name,
            'subject_type' => $subjectType,
            'subject_id' => $activity->subject_id,
            'description' => $activity->description,
            'properties' => [
                'old' => $props['old'] ?? null,
                'new' => $props['attributes'] ?? ($props['new'] ?? null),
                'change_reason' => $props['change_reason'] ?? null,
                'semantic_event' => $props['semantic_event'] ?? null,
            ],
            'causer' => $causer,
            'created_at' => $activity->created_at?->toISOString(),
        ];
    }

    /**
     * For every child subject class, return the list of row IDs tied to this pet.
     * Includes soft-deleted rows via withTrashed() so archived records still
     * appear in the audit trail.
     *
     * @return array<class-string, int[]>
     */
    private function collectChildSubjectIds(int $petId): array
    {
        $out = [];
        foreach (self::CHILD_SUBJECT_CLASSES as $class) {
            $query = $class::query()->where('pet_id', $petId);
            if (method_exists($class, 'bootSoftDeletes') || in_array('Illuminate\\Database\\Eloquent\\SoftDeletes', class_uses_recursive($class))) {
                $query->withTrashed();
            }
            $out[$class] = $query->pluck('id')->all();
        }

        return $out;
    }
}
