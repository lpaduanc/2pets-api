<?php

namespace App\Http\Controllers\Api;

use App\Enums\MedicalRecordStatus;
use App\Exceptions\Professional\ClientNotManuallyLinkedException;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professional\StoreProfessionalClientRequest;
use App\Http\Resources\UserResource;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\ProfessionalClient;
use App\Models\User;
use App\Services\Professional\ClientInviteService;
use App\Services\Professional\ClientPortalStatusService;
use App\Services\Professional\ClientProvisioningService;
use App\Services\Professional\ProfessionalClientsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class ProfessionalClientController extends Controller
{
    use PaginatesResults;

    /**
     * The client list feeds the appointment and invoice form selects, which load
     * it in full — hence a page large enough to hold a whole client book.
     */
    private const DEFAULT_PER_PAGE = 200;

    public function __construct(
        private readonly ClientProvisioningService $clientProvisioningService,
        private readonly ProfessionalClientsQuery $clientsQuery,
        private readonly ClientInviteService $clientInviteService,
        private readonly ClientPortalStatusService $clientPortalStatusService,
    ) {}

    /**
     * Display a listing of the resource.
     *
     * A "client" of the professional is any user that:
     *   - has appointments with this professional, OR
     *   - has invoices with this professional, OR
     *   - is the tutor of a pet to which this professional holds an active PetVetAccess grant, OR
     *   - was cadastrado manualmente pelo profissional (`professional_clients`, ver `store`).
     *
     * Cliente com a própria conta desativada (`AccountDeactivationService`) continua na
     * lista por padrão — ele foi cliente de verdade, e sumir sem explicação confundiria o
     * profissional mais do que mostrá-lo marcado como inativo (`is_active: false`). Quem
     * quer só a carteira ativa usa `?active_only=1`. Um cliente anonimizado por LGPD
     * (`deleted_at` preenchido) nunca aparece — soft delete já exclui via o global scope
     * do model, antes mesmo de chegar em `clientsQuery()`.
     */
    public function index(Request $request)
    {
        $professionalId = $request->user()->id;

        $query = $this->clientsQuery->query($professionalId)->with('pets');

        if ($request->boolean('active_only')) {
            $query->whereNull('deactivated_at');
        }

        // Busca por nome OU CPF, para o profissional achar o tutor no agendamento sem
        // precisar rolar a lista inteira.
        //
        // ESCOPO DELIBERADO: filtra DENTRO de `clientsQuery($professionalId)`, ou seja, só
        // entre os clientes do próprio profissional. Nunca uma busca global de tutores —
        // busca aberta por nome vaza dado pessoal, e por CPF vira enumeração de cadastro
        // (o mesmo padrão de risco apontado na revisão de segurança do fluxo de paciente
        // novo). Tutor que ainda não é cliente entra pelo caminho de paciente novo.
        if (filled($term = $request->query('q'))) {
            $digits = preg_replace('/\D/', '', (string) $term);

            $query->where(function ($scoped) use ($term, $digits) {
                $scoped->where('name', 'ILIKE', '%'.$term.'%');

                // Só compara CPF quando o termo tem dígito suficiente para ser um CPF
                // parcial — senão "Ana" viraria busca de CPF e traria ruído.
                if (strlen($digits) >= 3) {
                    $scoped->orWhereRaw("regexp_replace(COALESCE(cpf, ''), '\\D', '', 'g') LIKE ?", ['%'.$digits.'%']);
                }
            });
        }

        $clients = $query
            ->orderBy('name')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE))
            ->through(fn (User $client) => $client->setAttribute('is_active', ! $client->isDeactivated()));

        return JsonResource::collection($clients);
    }

    /**
     * Cadastra um cliente em nome do profissional. E-mail já existente vincula à conta em
     * vez de duplicar; conta nova nunca nasce com senha conhecida por terceiros — ver
     * `ClientProvisioningService`.
     */
    public function store(StoreProfessionalClientRequest $request)
    {
        $client = $this->clientProvisioningService->provision($request->validated(), $request->user()->id);

        return (new UserResource($client))->response()->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $client = $this->clientsQuery->query(Auth::id())
            ->where('id', $id)
            ->with('pets')
            ->firstOrFail();

        return response()->json($client);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $client = $this->clientsQuery->query(Auth::id())->where('id', $id)->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,'.$id,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
        ]);

        $client->update($validated);

        return response()->json($client);
    }

    /**
     * Desfaz o vínculo MANUAL entre o profissional e o cliente (`professional_clients`).
     *
     * "Cliente" aqui é majoritariamente derivado (agendamento, fatura, grant de acesso ao
     * pet) — não existe uma linha própria para "remover" nesses casos, e apagar a conta
     * inteira do usuário porque ela some da lista de um profissional era o bug real que
     * existia antes: qualquer clínica podia deletar a conta de um tutor que tivesse tido
     * um único agendamento com ela. Só o vínculo manual pode ser desfeito por aqui.
     */
    public function destroy(string $id)
    {
        $professionalId = Auth::id();

        $link = ProfessionalClient::query()
            ->where('professional_id', $professionalId)
            ->where('client_id', $id)
            ->first();

        if ($link === null) {
            throw new ClientNotManuallyLinkedException;
        }

        $link->delete();

        return response()->json(null, 204);
    }

    /**
     * Get all pets for a specific client — only the pets this professional is
     * allowed to see (owner + either appointment history, invoice history, OR
     * a specific PetVetAccess grant for that pet).
     */
    public function pets(string $id)
    {
        $professionalId = Auth::id();

        $client = $this->clientsQuery->query($professionalId)->where('id', $id)->firstOrFail();

        // Scope to pets the professional actually has a grant for (or any pet of
        // this tutor when the relationship is via appointment/invoice history).
        $grantedPetIds = PetVetAccess::query()
            ->where('veterinarian_id', $professionalId)
            ->active()
            ->pluck('pet_id')
            ->all();

        $pets = $client->pets()
            ->where(function ($q) use ($grantedPetIds) {
                // If there are granted pet IDs, allow those; otherwise fall back to all of
                // the tutor's pets (appointment/invoice-based relationship).
                if (! empty($grantedPetIds)) {
                    $q->whereIn('id', $grantedPetIds);
                }
            })
            ->get();

        $petIdsWithFinalizedRecord = MedicalRecord::query()
            ->whereIn('pet_id', $pets->pluck('id'))
            ->where('status', MedicalRecordStatus::FINALIZED)
            ->distinct()
            ->pluck('pet_id');

        $pets->each(function (Pet $pet) use ($petIdsWithFinalizedRecord) {
            $pet->setAttribute('has_finalized_record', $petIdsWithFinalizedRecord->contains($pet->id));
        });

        return response()->json($pets);
    }

    /**
     * Dispara (ou reenvia) o convite de vínculo fora do fluxo de agendamento — contrato
     * `docs/gap-simplesvet/specs/19-portal-do-cliente-spec.md` (Achado 1).
     */
    public function invite(string $id): JsonResponse
    {
        $client = $this->clientsQuery->query(Auth::id())->where('id', $id)->firstOrFail();

        $this->clientInviteService->invite(Auth::user(), $client);

        return response()->json(['message' => 'Convite enviado com sucesso.']);
    }

    /**
     * `{ state: no_account|invited|active }` — contrato
     * `docs/gap-simplesvet/specs/19-portal-do-cliente-spec.md` item 4.
     */
    public function portalStatus(string $id): JsonResponse
    {
        $client = $this->clientsQuery->query(Auth::id())->where('id', $id)->firstOrFail();

        $state = $this->clientPortalStatusService->resolve($client);

        return response()->json(['state' => $state->value]);
    }
}
