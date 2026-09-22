<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\AttachTagRequest;
use App\Http\Resources\Crm\TagResource;
use App\Models\Tag;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Professional\ProfessionalClientsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Atribui/remove etiquetas de um cliente específico — ficha do cliente (contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`, "Telas sugeridas"). `tag_id`
 * precisa já existir e ser do escopo comercial do profissional; a criação da etiqueta em si é
 * `POST tags` (`TagController`).
 */
class ClientTagController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly ProfessionalClientsQuery $clientsQuery,
    ) {}

    /**
     * `GET clients/{id}/tags` — faltava leitura das tags atribuídas (só existia
     * attach/detach), achado de revisão do front de 18. Ficha do cliente precisa listar antes
     * de poder remover.
     */
    public function index(Request $request, int $clientId): AnonymousResourceCollection
    {
        $client = $this->resolveClient($request->user(), $clientId);

        return TagResource::collection($client->tags()->get());
    }

    public function attach(AttachTagRequest $request, int $clientId): JsonResponse
    {
        $client = $this->resolveClient($request->user(), $clientId);
        $tag = $this->resolveOrCreateTag($request);

        $client->tags()->syncWithoutDetaching([$tag->id]);

        return response()->json(['message' => 'Etiqueta atribuída com sucesso.'], 201);
    }

    public function detach(Request $request, int $clientId, Tag $tag): JsonResponse
    {
        $client = $this->resolveClient($request->user(), $clientId);
        abort_unless($this->scope->userCanAccess($tag, $request->user()), 403);

        $client->tags()->detach($tag->id);

        return response()->json(['message' => 'Etiqueta removida do cliente.']);
    }

    private function resolveClient(User $professional, int $clientId): User
    {
        $teamUserIds = $this->scope->teamUserIds($professional);

        return $this->clientsQuery->queryForAny($teamUserIds)->findOrFail($clientId);
    }

    /** Cria a etiqueta na hora se `name` foi enviado em vez de `tag_id` (fluxo "digitar e criar"). */
    private function resolveOrCreateTag(AttachTagRequest $request): Tag
    {
        $tagId = $request->integer('tag_id');

        if ($tagId > 0) {
            return $this->scope->scopeQuery(Tag::query(), $request->user())->findOrFail($tagId);
        }

        return Tag::create(['name' => $request->string('name')->toString()] + $this->scope->ownershipFor($request->user()));
    }
}
