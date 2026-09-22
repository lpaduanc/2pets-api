<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreTagRequest;
use App\Http\Resources\Crm\TagResource;
use App\Models\Tag;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `tags` — contrato `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`, Escopo agora
 * item 7. MVP: só se liga a `User` (cliente); ver `ClientTagController` para atribuir/remover
 * de um cliente específico.
 */
class TagController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tags = $this->scope->scopeQuery(Tag::query(), $request->user())
            ->orderBy('name')
            ->get();

        return TagResource::collection($tags);
    }

    public function store(StoreTagRequest $request): JsonResponse
    {
        $tag = Tag::create($request->validated() + $this->scope->ownershipFor($request->user()));

        return response()->json(['data' => new TagResource($tag)], 201);
    }

    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        abort_unless($this->scope->userCanAccess($tag, $request->user()), 403);

        $tag->delete();

        return response()->json(['message' => 'Etiqueta removida com sucesso.']);
    }
}
