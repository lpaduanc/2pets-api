<?php

namespace App\Http\Controllers\Api;

use App\Enums\VetAccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\PetVetAccess\GrantVetAccessRequest;
use App\Http\Requests\PetVetAccess\RequestVetAccessRequest;
use App\Http\Resources\PetPatientResource;
use App\Http\Resources\PetVetAccessResource;
use App\Models\Appointment;
use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Models\Vaccination;
use App\Notifications\PetVetAccessRequested;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PetVetAccessController extends Controller
{
    /**
     * Tutor concede acesso ao veterinário para visualizar dados do pet.
     *
     * Apenas o dono do pet pode conceder acesso.
     */
    public function grant(GrantVetAccessRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $pet = Pet::findOrFail($data['pet_id']);

        // Verifica se o usuário autenticado é o dono do pet
        if ($pet->user_id !== $user->id) {
            return response()->json([
                'message' => 'Apenas o tutor dono do pet pode conceder acesso.',
            ], 403);
        }

        // Verifica se o veterinário existe e tem role adequada
        $vet = User::findOrFail($data['veterinarian_id']);
        if (! $vet->hasAnyRole(['veterinarian', 'vet_freelancer', 'clinic_vet'])) {
            return response()->json([
                'message' => 'O usuário informado não é um profissional veterinário.',
            ], 422);
        }

        // Verifica se já existe acesso ativo para este vet+pet
        $existingAccess = PetVetAccess::where('pet_id', $pet->id)
            ->where('veterinarian_id', $vet->id)
            ->where('is_active', true)
            ->first();

        if ($existingAccess) {
            return response()->json([
                'message' => 'Este veterinário já possui acesso ativo a este pet.',
                'data' => new PetVetAccessResource($existingAccess),
            ], 409);
        }

        $accessLevel = VetAccessLevel::tryFrom($data['access_level'] ?? 'read') ?? VetAccessLevel::READ;

        $access = PetVetAccess::create([
            'pet_id' => $pet->id,
            'veterinarian_id' => $vet->id,
            'granted_by' => $user->id,
            'access_level' => $accessLevel,
            'granted_at' => now(),
            'is_active' => true,
            // Tutor-initiated grants are immediately accepted — skip the pending handshake.
            'status' => PetVetAccess::STATUS_ACCEPTED,
        ]);

        $access->load(['pet', 'veterinarian', 'grantor']);

        return response()->json([
            'message' => 'Acesso concedido com sucesso.',
            'data' => new PetVetAccessResource($access),
        ], 201);
    }

    /**
     * Veterinário solicita acesso a um pet. Acesso fica em status `pending` até o tutor aceitar.
     *
     * Caminhos suportados:
     *   1. Vet conhece o pet na plataforma → envia `pet_id`.
     *   2. Vet iniciou atendimento e só tem o CPF do tutor → envia `tutor_cpf` + `pet_data`;
     *      o pet é criado em nome do tutor e a solicitação fica pendente.
     */
    public function requestAccess(RequestVetAccessRequest $request): JsonResponse
    {
        $vet = $request->user();
        $data = $request->validated();

        if (! $vet->hasAnyRole(['veterinarian', 'vet_freelancer', 'clinic_vet'])) {
            return response()->json(['message' => 'Apenas veterinários podem solicitar acesso a pets.'], 403);
        }

        return DB::transaction(function () use ($vet, $data) {
            if (! empty($data['pet_id'])) {
                $pet = Pet::findOrFail($data['pet_id']);
                $tutor = User::findOrFail($pet->user_id);
            } else {
                $cpfClean = preg_replace('/\D/', '', (string) $data['tutor_cpf']);
                $tutor = User::where('cpf', $cpfClean)->first();
                if (! $tutor) {
                    return response()->json([
                        'message' => 'Nenhum tutor encontrado com este CPF. Oriente o tutor a se cadastrar na plataforma antes.',
                    ], 404);
                }

                $pet = Pet::create(array_merge($data['pet_data'], ['user_id' => $tutor->id]));
            }

            // Dedupe: uma solicitação pendente ou aceita já existente para este vet+pet bloqueia nova solicitação.
            $existing = PetVetAccess::where('pet_id', $pet->id)
                ->where('veterinarian_id', $vet->id)
                ->whereIn('status', [PetVetAccess::STATUS_PENDING, PetVetAccess::STATUS_ACCEPTED])
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Já existe uma solicitação ativa para este pet.',
                    'data' => new PetVetAccessResource($existing->load(['pet', 'veterinarian', 'grantor'])),
                ], 409);
            }

            $access = PetVetAccess::create([
                'pet_id' => $pet->id,
                'veterinarian_id' => $vet->id,
                'granted_by' => $tutor->id,
                'access_level' => VetAccessLevel::tryFrom($data['access_level'] ?? 'read') ?? VetAccessLevel::READ,
                'status' => PetVetAccess::STATUS_PENDING,
                'requested_at' => now(),
                'is_active' => false,
            ]);

            // Notify the tutor — queued to not block the response.
            $crmv = optional($vet->professional)->crmv
                ? $vet->professional->crmv.'/'.($vet->professional->crmv_state ?? 'BR')
                : null;

            try {
                $tutor->notify(new PetVetAccessRequested($access, $pet, $vet, $crmv));
            } catch (\Throwable $e) {
                // A notification failure must not block granting the pending request.
                Log::error('Failed to dispatch PetVetAccessRequested notification', [
                    'access_id' => $access->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('PetVetAccess requested', [
                'access_id' => $access->id,
                'vet_id' => $vet->id,
                'pet_id' => $pet->id,
                'tutor_id' => $tutor->id,
            ]);

            return response()->json([
                'message' => 'Solicitação enviada. O tutor foi notificado e precisa aceitar para liberar o acesso.',
                'data' => new PetVetAccessResource($access->load(['pet', 'veterinarian', 'grantor'])),
            ], 201);
        });
    }

    /**
     * Tutor aceita solicitação pendente. Só então o vet passa a ver os dados do pet.
     */
    public function accept(Request $request, int $accessId): JsonResponse
    {
        $user = $request->user();
        $access = PetVetAccess::with('pet')->findOrFail($accessId);

        if ($access->pet->user_id !== $user->id) {
            return response()->json(['message' => 'Apenas o tutor dono do pet pode aceitar.'], 403);
        }

        if ($access->status !== PetVetAccess::STATUS_PENDING) {
            return response()->json(['message' => 'Esta solicitação não está mais pendente.'], 422);
        }

        $access->accept();

        Log::info('PetVetAccess accepted', ['access_id' => $access->id, 'tutor_id' => $user->id]);

        return response()->json([
            'message' => 'Acesso liberado.',
            'data' => new PetVetAccessResource($access->load(['pet', 'veterinarian', 'grantor'])),
        ]);
    }

    /**
     * Tutor rejeita solicitação pendente.
     */
    public function reject(Request $request, int $accessId): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $user = $request->user();
        $access = PetVetAccess::with('pet')->findOrFail($accessId);

        if ($access->pet->user_id !== $user->id) {
            return response()->json(['message' => 'Apenas o tutor dono do pet pode rejeitar.'], 403);
        }

        if ($access->status !== PetVetAccess::STATUS_PENDING) {
            return response()->json(['message' => 'Esta solicitação não está mais pendente.'], 422);
        }

        $access->reject($data['reason'] ?? null);

        Log::info('PetVetAccess rejected', ['access_id' => $access->id, 'tutor_id' => $user->id]);

        return response()->json([
            'message' => 'Solicitação recusada.',
            'data' => new PetVetAccessResource($access->load(['pet', 'veterinarian', 'grantor'])),
        ]);
    }

    /**
     * Tutor revoga acesso previamente aceito.
     *
     * Além de marcar `revoked`, invalida tokens ativos do vet na plataforma como defesa em profundidade —
     * na próxima request o vet será forçado a reautenticar (e aí o guard de autorização já bloqueia).
     */
    public function revoke(Request $request, int $accessId): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $user = $request->user();
        $access = PetVetAccess::with('pet', 'veterinarian')->findOrFail($accessId);

        if ($access->pet->user_id !== $user->id) {
            return response()->json(['message' => 'Apenas o tutor dono do pet pode revogar acesso.'], 403);
        }

        if ($access->status !== PetVetAccess::STATUS_ACCEPTED) {
            return response()->json(['message' => 'Este acesso não está ativo.'], 422);
        }

        $access->revoke($user->id, $data['reason'] ?? null);

        // Log imutável para auditoria LGPD.
        Log::warning('PetVetAccess revoked', [
            'access_id' => $access->id,
            'tutor_id' => $user->id,
            'vet_id' => $access->veterinarian_id,
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json([
            'message' => 'Acesso revogado com sucesso.',
            'data' => new PetVetAccessResource($access->load(['pet', 'veterinarian', 'grantor'])),
        ]);
    }

    /**
     * Tutor lista suas solicitações pendentes.
     */
    public function pendingForTutor(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $accesses = PetVetAccess::with(['pet', 'veterinarian'])
            ->whereHas('pet', fn ($q) => $q->where('user_id', $user->id))
            ->pending()
            ->orderByDesc('requested_at')
            ->paginate(20);

        return PetVetAccessResource::collection($accesses);
    }

    /**
     * Veterinário lista todos os pets aos quais tem acesso ativo.
     *
     * Enriched payload (PetPatientResource): pet data + tutor data + last_visit_at +
     * next_event (soonest pending vaccine or deworming). This is what the vet's
     * "my patients" dashboard page consumes.
     */
    public function myAccesses(Request $request): AnonymousResourceCollection
    {
        return $this->buildPatientList($request);
    }

    /**
     * Alias — same contract as myAccesses but mounted under /professional/my-patients
     * for discoverability from the professional app namespace.
     */
    public function myPatients(Request $request): AnonymousResourceCollection
    {
        return $this->buildPatientList($request);
    }

    /**
     * Tutor lista todos os acessos veterinários concedidos para seus pets.
     */
    public function petAccesses(Request $request, int $petId): JsonResponse
    {
        $user = $request->user();

        $pet = Pet::findOrFail($petId);

        if ($pet->user_id !== $user->id) {
            return response()->json([
                'message' => 'Você não tem permissão para visualizar os acessos deste pet.',
            ], 403);
        }

        $accesses = PetVetAccess::with(['veterinarian', 'grantor'])
            ->where('pet_id', $pet->id)
            ->orderByDesc('is_active')
            ->orderByDesc('granted_at')
            ->get();

        return response()->json([
            'data' => PetVetAccessResource::collection($accesses),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Internals
    // ────────────────────────────────────────────────────────────────────

    /**
     * Build the enriched patient list shared by myAccesses + myPatients.
     */
    private function buildPatientList(Request $request): AnonymousResourceCollection
    {
        $vet = $request->user();

        $accesses = PetVetAccess::query()
            ->with([
                'pet.breedRelation',
                'grantor', // tutor
            ])
            ->where('veterinarian_id', $vet->id)
            ->active()
            ->orderByDesc('granted_at')
            ->paginate(20);

        // Decorate each access with last_visit_at and next_event. Done in PHP to
        // keep the SQL simple — if this shows up in the slow query log we can move
        // it to a single joined query.
        $petIds = $accesses->getCollection()->pluck('pet_id')->unique()->values();

        $lastVisits = Appointment::query()
            ->whereIn('pet_id', $petIds)
            ->where('professional_id', $vet->id)
            ->where('status', 'completed')
            ->selectRaw('pet_id, MAX(appointment_date) as last_visit')
            ->groupBy('pet_id')
            ->pluck('last_visit', 'pet_id');

        $upcomingVaccines = Vaccination::query()
            ->whereIn('pet_id', $petIds)
            ->whereNotNull('next_dose_date')
            ->where('next_dose_date', '>=', today())
            ->orderBy('next_dose_date')
            ->get(['pet_id', 'vaccine_name', 'next_dose_date'])
            ->groupBy('pet_id');

        $upcomingDewormings = PetDeworming::query()
            ->whereIn('pet_id', $petIds)
            ->whereNotNull('next_date')
            ->where('next_date', '>=', today())
            ->orderBy('next_date')
            ->get(['pet_id', 'product_name', 'next_date'])
            ->groupBy('pet_id');

        $accesses->getCollection()->transform(function (PetVetAccess $access) use ($lastVisits, $upcomingVaccines, $upcomingDewormings) {
            $petId = $access->pet_id;

            $access->setAttribute(
                'last_visit_at',
                isset($lastVisits[$petId]) ? \Carbon\Carbon::parse($lastVisits[$petId]) : null
            );

            // Pick the soonest pending event across vaccines + dewormings.
            $candidates = [];
            if (isset($upcomingVaccines[$petId])) {
                $v = $upcomingVaccines[$petId]->first();
                $candidates[] = [
                    'type' => 'vaccine',
                    'label' => $v->vaccine_name,
                    'due_at' => $v->next_dose_date?->toDateString(),
                    'due_at_ts' => $v->next_dose_date ? $v->next_dose_date->timestamp : PHP_INT_MAX,
                ];
            }
            if (isset($upcomingDewormings[$petId])) {
                $d = $upcomingDewormings[$petId]->first();
                $candidates[] = [
                    'type' => 'deworming',
                    'label' => $d->product_name,
                    'due_at' => $d->next_date?->toDateString(),
                    'due_at_ts' => $d->next_date ? $d->next_date->timestamp : PHP_INT_MAX,
                ];
            }

            $nextEvent = null;
            if (! empty($candidates)) {
                usort($candidates, fn ($a, $b) => $a['due_at_ts'] <=> $b['due_at_ts']);
                $first = $candidates[0];
                unset($first['due_at_ts']);
                $nextEvent = $first;
            }

            $access->setAttribute('next_event', $nextEvent);

            return $access;
        });

        return PetPatientResource::collection($accesses);
    }
}
