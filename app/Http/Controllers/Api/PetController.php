<?php

namespace App\Http\Controllers\Api;

use App\Enums\VetAccessLevel;
use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pet\StorePetRequest;
use App\Http\Requests\Pet\UpdatePetRequest;
use App\Http\Resources\PetDetailResource;
use App\Http\Resources\PetResource;
use App\Models\Breed;
use App\Models\Pet;
use App\Models\PetMedication;
use App\Models\PetVetAccess;
use App\Models\Vaccination;
use App\Notifications\PetUpdatedByVet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PetController extends Controller
{
    use AuthorizesPetAccess;

    /**
     * Spatie role names that identify a veterinarian. The `users.role` column is a
     * coarse bucket (`tutor|professional|admin`); the real role lives in Spatie —
     * always check `hasAnyRole(self::VET_ROLES)`, never the column directly.
     */
    private const VET_ROLES = ['veterinarian', 'vet_freelancer', 'clinic_vet'];

    private const PET_LIST_COLUMNS = [
        'id',
        'user_id',
        'name',
        'species',
        'breed',
        'breed_id',
        'gender',
        'birth_date',
        'weight',
        'size',
        'color',
        'neutered',
        'image_url',
        'is_lost',
        'lost_since',
        'public_id',
        'created_at',
        'updated_at',
    ];

    /**
     * Display a listing of pets.
     *
     * Supported scopes:
     *   - `mine` (default): pets the requester owns (tutor view — unchanged behavior).
     *   - `patients`: pets the requester (vet) has an active PetVetAccess grant for.
     */
    public function index(Request $request)
    {
        $scope = $request->input('scope', 'mine');
        $user = $request->user();
        $perPage = (int) $request->input('per_page', 20);

        $query = match ($scope) {
            'patients' => Pet::query()
                ->select(self::PET_LIST_COLUMNS)
                ->whereIn('id', function ($q) use ($user) {
                    $q->select('pet_id')
                        ->from('pet_vet_accesses')
                        ->where('veterinarian_id', $user->id)
                        ->where('status', PetVetAccess::STATUS_ACCEPTED)
                        ->where('is_active', true)
                        ->whereNull('revoked_at');
                }),
            default => $user->pets()->select(self::PET_LIST_COLUMNS),
        };

        $pets = $query->orderBy('name')->paginate($perPage);

        return PetResource::collection($pets);
    }

    /**
     * Vet-only lookup: find pets by tutor CPF so the vet can request access.
     *
     * Returns a minimal projection (no medical data) — the vet is expected to
     * follow up with POST /pet-vet-access/request to get the real grant.
     */
    public function search(Request $request)
    {
        $user = $request->user();

        if (! $user->hasAnyRole(self::VET_ROLES)) {
            abort(403, 'Somente veterinários podem buscar pets por CPF do tutor.');
        }

        $cpfClean = preg_replace('/\D/', '', (string) $request->input('tutor_cpf', ''));
        if (strlen($cpfClean) !== 11) {
            return response()->json([
                'message' => 'Informe um CPF válido (11 dígitos).',
                'errors' => ['tutor_cpf' => ['CPF deve conter 11 dígitos.']],
            ], 422);
        }

        $tutor = \App\Models\User::where('cpf', $cpfClean)->first();
        if (! $tutor) {
            return response()->json(['data' => []]);
        }

        $pets = Pet::where('user_id', $tutor->id)
            ->select(['id', 'user_id', 'name', 'species', 'breed', 'birth_date', 'image_url', 'public_id'])
            ->orderBy('name')
            ->limit(50)
            ->get();

        $data = $pets->map(fn ($pet) => [
            'id' => $pet->id,
            'name' => $pet->name,
            'species' => $pet->species,
            'breed' => $pet->breed,
            'birth_date' => $pet->birth_date,
            'image_url' => $pet->image_url,
            'public_id' => $pet->public_id,
            'tutor' => [
                'id' => $tutor->id,
                'name' => $tutor->name,
            ],
        ]);

        return response()->json(['data' => $data]);
    }

    public function store(StorePetRequest $request)
    {
        $data = $this->mapPayload($request, $request->validated());
        $data['user_id'] = $request->user()->id;

        [$vaccines, $medications] = $this->extractNested($data);

        $pet = DB::transaction(function () use ($request, $data, $vaccines, $medications) {
            if ($request->hasFile('photo')) {
                $data['image_url'] = $this->storePhoto($request->file('photo'));
            }

            $pet = Pet::create($data);

            $this->syncVaccines($pet, $vaccines, $request->user()->id);
            $this->syncMedications($pet, $medications);

            return $pet;
        });

        return (new PetResource($pet))
            ->additional(['message' => 'Pet criado com sucesso!'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, $id)
    {
        $pet = $this->resolvePetForRead($request, (int) $id);

        $user = $request->user();
        $isOwner = $this->isPetOwner($user, $pet);

        $relations = [
            'vaccinations' => fn ($q) => $q->orderByDesc('application_date')->limit(50),
            'dewormings' => fn ($q) => $q->orderByDesc('applied_date')->limit(20),
            'medications' => fn ($q) => $q->where('active', true),
            'weightHistory' => fn ($q) => $q->orderByDesc('measured_at')->limit(30),
            'breedRelation',
        ];

        // Never expose the list of other vets to a visiting vet — that's tutor-only info.
        if ($isOwner) {
            $relations['vetAccesses'] = fn ($q) => $q->where('is_active', true);
        }

        $pet->load($relations);

        // Surface the requester's effective access level so the frontend can gate
        // write UI correctly. Owner → full; vet → whatever their PetVetAccess grants.
        $pet->setAttribute(
            'access_level_for_requester',
            $isOwner
                ? VetAccessLevel::FULL->value
                : (PetVetAccess::query()
                    ->where('pet_id', $pet->id)
                    ->where('veterinarian_id', $user->id)
                    ->active()
                    ->value('access_level') ?? VetAccessLevel::READ->value)
        );

        return new PetDetailResource($pet);
    }

    public function update(UpdatePetRequest $request, $id)
    {
        // Owner or vet with FULL grant can mutate the pet row.
        $pet = $this->resolvePetForFullMutation($request, (int) $id);

        $data = $this->mapPayload($request, $request->validated());
        $changeReason = $data['change_reason'] ?? null;
        unset($data['change_reason']);

        [$vaccines, $medications] = $this->extractNested($data);

        // Snapshot of fillable attributes before the update so we can compute the
        // field-level diff for the tutor-facing notification summary.
        $beforeAttrs = $pet->getAttributes();

        DB::transaction(function () use ($request, $pet, $data, $vaccines, $medications) {
            if ($request->hasFile('photo')) {
                $data['image_url'] = $this->storePhoto($request->file('photo'));
            }

            $pet->update($data);

            if ($vaccines !== null) {
                $this->syncVaccines($pet, $vaccines, $request->user()->id);
            }
            if ($medications !== null) {
                $this->syncMedications($pet, $medications);
            }
        });

        $this->attachChangeReasonToLatestActivity($pet, $changeReason);

        // Notify owner if the mutation was done by a third party (vet with FULL grant).
        $user = $request->user();
        if (! $this->isPetOwner($user, $pet)) {
            $changedFields = $this->diffFields($beforeAttrs, $pet->fresh()->getAttributes());
            $this->dispatchVetUpdateNotification($pet, $user, 'pet_info', [
                'field_summary' => $changedFields,
                'change_reason' => $changeReason,
            ]);
        }

        return (new PetResource($pet->fresh()))
            ->additional(['message' => 'Pet atualizado com sucesso!']);
    }

    public function destroy(Request $request, $id)
    {
        $pet = $this->resolvePetForFullMutation($request, (int) $id);
        $pet->delete();

        return response()->json([
            'message' => 'Pet removido com sucesso!',
        ]);
    }

    /**
     * Maps frontend aliases to the canonical column names the model expects.
     * Also resolves breed_id → breed (denormalized name for quick display).
     */
    private function mapPayload(Request $request, array $data): array
    {
        if (isset($data['weight_kg']) && ! isset($data['weight'])) {
            $data['weight'] = $data['weight_kg'];
        }
        unset($data['weight_kg']);

        if (isset($data['is_neutered'])) {
            $status = $data['is_neutered'];
            $data['neutered_status'] = $status;
            $data['neutered'] = $status === 'yes';
            unset($data['is_neutered']);
        }

        if (! empty($data['breed_id'])) {
            $breed = Breed::find($data['breed_id']);
            if ($breed) {
                $data['breed'] = $breed->name;
            }
        }

        return $data;
    }

    private function extractNested(array &$data): array
    {
        $vaccines = $data['vaccines'] ?? null;
        $medications = $data['medications'] ?? null;
        unset($data['vaccines'], $data['medications'], $data['photo']);

        return [$vaccines, $medications];
    }

    private function syncVaccines(Pet $pet, ?array $vaccines, int $userId): void
    {
        if (! is_array($vaccines)) {
            return;
        }
        foreach ($vaccines as $v) {
            if (empty($v['name']) || empty($v['applied_date'])) {
                continue;
            }
            Vaccination::create([
                'pet_id' => $pet->id,
                'professional_id' => $userId,
                'vaccine_name' => $v['name'],
                'application_date' => $v['applied_date'],
                'next_dose_date' => $v['next_dose_date'] ?? null,
                'dose_number' => 1,
            ]);
        }
    }

    private function syncMedications(Pet $pet, ?array $medications): void
    {
        if (! is_array($medications)) {
            return;
        }
        foreach ($medications as $m) {
            if (empty($m['name'])) {
                continue;
            }
            PetMedication::create([
                'pet_id' => $pet->id,
                'name' => $m['name'],
                'dosage' => $m['dosage'] ?? '',
                'frequency' => $m['frequency'] ?? '',
                'start_date' => now()->toDateString(),
                'active' => true,
            ]);
        }
    }

    private function storePhoto($file): string
    {
        // Store in the 'public' disk so Storage::disk('public')->url() returns an absolute URL
        // using filesystems.disks.public.url (backend origin) — required because the SPA runs
        // on a different port (9200) than the API (8000) in development.
        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('pets', $filename, 'public');

        return Storage::disk('public')->url($path);
    }

    /**
     * Compute a human-readable list of pet columns that changed. We compare
     * the raw attribute arrays because cast/accessor objects aren't reliably
     * loose-equal — strings and scalars are what we care about for the UI.
     *
     * @return string[]
     */
    private function diffFields(array $before, array $after): array
    {
        $ignore = ['updated_at', 'created_at', 'id'];
        $changed = [];
        foreach ($after as $key => $val) {
            if (in_array($key, $ignore, true)) {
                continue;
            }
            $prev = $before[$key] ?? null;
            if ($prev != $val) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * Annotate the most recent Pet activity log entry with the caller-supplied
     * change_reason — so the audit timeline can render "motivo: X" inline.
     */
    private function attachChangeReasonToLatestActivity(Pet $pet, ?string $reason): void
    {
        if ($reason === null || $reason === '') {
            return;
        }

        $activity = $pet->activities()->latest('id')->first();
        if (! $activity) {
            return;
        }

        $props = $activity->properties?->toArray() ?? [];
        $props['change_reason'] = $reason;
        $activity->properties = $props;
        $activity->save();
    }

    /**
     * Best-effort notification dispatch. A delivery failure never blocks the
     * request — we log and move on. Same pattern as PetVetAccessRequested.
     */
    private function dispatchVetUpdateNotification(Pet $pet, $vet, string $eventType, array $payload = []): void
    {
        try {
            $pet->loadMissing('user');
            $owner = $pet->user;
            if ($owner) {
                $owner->notify(new PetUpdatedByVet($pet, $vet, $eventType, $payload));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch PetUpdatedByVet', [
                'pet_id' => $pet->id,
                'vet_id' => $vet?->id,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
