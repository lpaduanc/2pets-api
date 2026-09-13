<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Professional\ClientNotManuallyLinkedException;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professional\StoreProfessionalClientRequest;
use App\Http\Resources\UserResource;
use App\Models\PetVetAccess;
use App\Models\ProfessionalClient;
use App\Models\User;
use App\Services\Professional\ClientProvisioningService;
use Illuminate\Database\Eloquent\Builder;
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

        $query = $this->clientsQuery($professionalId)->with('pets');

        if ($request->boolean('active_only')) {
            $query->whereNull('deactivated_at');
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
        $client = $this->clientsQuery(Auth::id())
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
        $client = $this->clientsQuery(Auth::id())->where('id', $id)->firstOrFail();

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

        $client = $this->clientsQuery($professionalId)->where('id', $id)->firstOrFail();

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

        return response()->json($pets);
    }

    /**
     * Query base de "quem é cliente deste profissional" — compartilhada por index/show/update/
     * pets para as quatro fontes de derivação nunca ficarem dessincronizadas entre si de novo
     * (o bug original: `destroy` nem sequer olhava PetVetAccess, `show`/`update`/`pets` não
     * enxergavam vínculo manual). Um usuário que se encaixa em mais de uma fonte aparece uma
     * única vez — é um único `WHERE ... OR ...`, não uma união de listas.
     */
    private function clientsQuery(int $professionalId): Builder
    {
        return User::where('id', '!=', $professionalId)
            ->where(function ($query) use ($professionalId) {
                $query->whereHas('appointmentsAsClient', function ($q) use ($professionalId) {
                    $q->where('professional_id', $professionalId);
                })
                    ->orWhereHas('invoicesAsClient', function ($q) use ($professionalId) {
                        $q->where('professional_id', $professionalId);
                    })
                    ->orWhereIn('id', $this->tutorIdsWithActiveGrantTo($professionalId))
                    ->orWhereIn('id', $this->manuallyLinkedClientIds($professionalId));
            });
    }

    /**
     * Subquery-style helper returning tutor IDs whose pets have an active grant for this professional.
     */
    private function tutorIdsWithActiveGrantTo(int $professionalId)
    {
        return PetVetAccess::query()
            ->where('veterinarian_id', $professionalId)
            ->active()
            ->join('pets', 'pets.id', '=', 'pet_vet_accesses.pet_id')
            ->distinct()
            ->pluck('pets.user_id');
    }

    /** IDs de cliente com vínculo manual vivo (`professional_clients`, ver `store`). */
    private function manuallyLinkedClientIds(int $professionalId)
    {
        return ProfessionalClient::query()
            ->where('professional_id', $professionalId)
            ->pluck('client_id');
    }
}
