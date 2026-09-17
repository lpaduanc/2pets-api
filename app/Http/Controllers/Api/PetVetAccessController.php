<?php

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\VetAccessRequestData;
use App\Enums\MedicalRecordStatus;
use App\Enums\VetAccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\PetVetAccess\AcceptVetAccessRequest;
use App\Http\Requests\PetVetAccess\ChangeVetAccessLevelRequest;
use App\Http\Requests\PetVetAccess\GrantVetAccessRequest;
use App\Http\Requests\PetVetAccess\RequestVetAccessRequest;
use App\Http\Resources\PetPatientResource;
use App\Http\Resources\PetVetAccessResource;
use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\PetAccess\PatientSearchFilter;
use App\Services\PetAccess\VetAccessGrantService;
use App\Services\PetAccess\VetAccessRequestService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PetVetAccessController extends Controller
{
    // A Controller base do projeto não traz o trait — sem ele, `$this->authorize()` seria
    // "method not found" em runtime e a autorização por Policy simplesmente não aconteceria.
    use AuthorizesRequests;

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
        if (! $vet->isVeterinarian()) {
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

        $access->load(['pet', ...PetVetAccess::PARTICIPANT_RELATIONS]);

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
    public function requestAccess(RequestVetAccessRequest $request, VetAccessRequestService $service): JsonResponse
    {
        $access = $service->request(
            $request->user(),
            VetAccessRequestData::fromValidated($request->validated())
        );

        return response()->json([
            'message' => 'Solicitação enviada. O tutor foi notificado e precisa aceitar para liberar o acesso.',
            'data' => new PetVetAccessResource($access->load(['pet', ...PetVetAccess::PARTICIPANT_RELATIONS])),
        ], 201);
    }

    /**
     * Tutor aceita solicitação pendente E define o nível concedido.
     *
     * O `requested_access_level` enviado pelo vet é só indicação de necessidade: o nível que
     * vale é o `access_level` deste corpo. Aceitar um pedido de upgrade substitui a concessão
     * anterior (ver VetAccessGrantService).
     */
    public function accept(AcceptVetAccessRequest $request, VetAccessGrantService $service, int $accessId): JsonResponse
    {
        $access = PetVetAccess::with('pet')->findOrFail($accessId);
        $this->authorize('respond', $access);

        $granted = $service->accept($access, $request->grantedLevel(), $request->user());

        return $this->respondWithAccess($granted, 'Acesso liberado.');
    }

    /**
     * Tutor altera o nível de um acesso já concedido, para cima ou para baixo, sem precisar de
     * nova solicitação do veterinário.
     *
     * A resposta traz a linha VIGENTE, que tem id novo quando o nível muda de fato: cada nível
     * é uma janela própria e a anterior fica em `superseded` para a auditoria.
     */
    public function changeLevel(ChangeVetAccessLevelRequest $request, VetAccessGrantService $service, int $accessId): JsonResponse
    {
        $access = PetVetAccess::with('pet')->findOrFail($accessId);
        $this->authorize('changeLevel', $access);

        $updated = $service->changeLevel($access, $request->newLevel(), $request->user());

        return $this->respondWithAccess($updated, 'Nível de acesso atualizado.');
    }

    /**
     * Tutor rejeita solicitação pendente. O vet é notificado (in-app) da recusa.
     */
    public function reject(Request $request, VetAccessGrantService $service, int $accessId): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $access = PetVetAccess::with('pet')->findOrFail($accessId);
        $this->authorize('respond', $access);

        $rejected = $service->reject($access, $request->user(), $data['reason'] ?? null);

        return $this->respondWithAccess($rejected, 'Solicitação recusada.');
    }

    /**
     * Tutor revoga acesso previamente aceito. O vet é notificado (e-mail + in-app) — sem isso
     * ele só descobre a revogação levando um 403 na frente do cliente.
     *
     * Além de marcar `revoked`, invalida tokens ativos do vet na plataforma como defesa em profundidade —
     * na próxima request o vet será forçado a reautenticar (e aí o guard de autorização já bloqueia).
     */
    public function revoke(Request $request, VetAccessGrantService $service, int $accessId): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $access = PetVetAccess::with('pet', 'veterinarian')->findOrFail($accessId);
        $this->authorize('revoke', $access);

        $revoked = $service->revoke($access, $request->user(), $data['reason'] ?? null);

        return $this->respondWithAccess($revoked, 'Acesso revogado com sucesso.');
    }

    /**
     * Tutor lista suas solicitações pendentes.
     */
    public function pendingForTutor(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $accesses = PetVetAccess::with(['pet', ...PetVetAccess::PARTICIPANT_RELATIONS])
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
    public function myAccesses(Request $request, PatientSearchFilter $searchFilter): AnonymousResourceCollection
    {
        return $this->buildPatientList($request, $searchFilter);
    }

    /**
     * Alias — same contract as myAccesses but mounted under /professional/my-patients
     * for discoverability from the professional app namespace.
     */
    public function myPatients(Request $request, PatientSearchFilter $searchFilter): AnonymousResourceCollection
    {
        return $this->buildPatientList($request, $searchFilter);
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

        $accesses = PetVetAccess::with(PetVetAccess::PARTICIPANT_RELATIONS)
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

    /** Resposta padrão dos endpoints que devolvem um vínculo com as duas partes carregadas. */
    private function respondWithAccess(PetVetAccess $access, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new PetVetAccessResource($access->load(['pet', ...PetVetAccess::PARTICIPANT_RELATIONS])),
        ]);
    }

    /**
     * Build the enriched patient list shared by myAccesses + myPatients.
     *
     * `?q=` filters server-side (pet name or tutor name) — the app used to filter the loaded
     * page in JavaScript, which silently missed every patient past the first page.
     */
    private function buildPatientList(Request $request, PatientSearchFilter $searchFilter): AnonymousResourceCollection
    {
        $vet = $request->user();

        $query = PetVetAccess::query()
            ->with([
                'pet.breedRelation',
                'grantor', // tutor
            ])
            ->where('veterinarian_id', $vet->id)
            ->active();

        $accesses = $searchFilter->apply($query, $request->query('q'))
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

        $petIdsWithFinalizedRecord = $this->finalizedRecordPetIds($petIds);

        $accesses->getCollection()->transform(function (PetVetAccess $access) use ($lastVisits, $upcomingVaccines, $upcomingDewormings, $petIdsWithFinalizedRecord) {
            $petId = $access->pet_id;

            $access->setAttribute(
                'last_visit_at',
                isset($lastVisits[$petId]) ? \Carbon\Carbon::parse($lastVisits[$petId]) : null
            );

            // "Ainda não passou em consulta" é uma pergunta sobre o PET, não sobre o vínculo
            // com ESTE profissional — `last_visit_at` (acima) é o oposto disso de propósito e
            // não deve virar a fonte desse selo (ver docs/atendimento-veterinario/
            // 07-contrato-agendamento-pet-novo.md §2).
            $access->setAttribute('has_finalized_record', $petIdsWithFinalizedRecord->contains($petId));

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

    /**
     * Pets, dentre os informados, com AO MENOS UM `medical_record` finalizado — global ao pet,
     * nunca escopado a este profissional (regra do dono do produto: "o prontuário pertence
     * sempre ao pet"). Uma query, independente do número de pets na página.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $petIds
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function finalizedRecordPetIds($petIds)
    {
        return MedicalRecord::query()
            ->whereIn('pet_id', $petIds)
            ->where('status', MedicalRecordStatus::FINALIZED)
            ->distinct()
            ->pluck('pet_id');
    }
}
