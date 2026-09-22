<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreMessageTemplateRequest;
use App\Http\Resources\Crm\MessageTemplateResource;
use App\Models\MessageTemplate;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** `message-templates` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. */
class MessageTemplateController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $templates = $this->scope->scopeQuery(MessageTemplate::query(), $request->user())
            ->orderByDesc('created_at')
            ->get();

        return MessageTemplateResource::collection($templates);
    }

    public function store(StoreMessageTemplateRequest $request): JsonResponse
    {
        $template = MessageTemplate::create($request->validated() + $this->scope->ownershipFor($request->user()));

        return response()->json(['data' => new MessageTemplateResource($template)], 201);
    }

    public function show(Request $request, MessageTemplate $messageTemplate): MessageTemplateResource
    {
        abort_unless($this->scope->userCanAccess($messageTemplate, $request->user()), 403);

        return new MessageTemplateResource($messageTemplate);
    }

    public function update(StoreMessageTemplateRequest $request, MessageTemplate $messageTemplate): JsonResponse
    {
        abort_unless($this->scope->userCanAccess($messageTemplate, $request->user()), 403);

        $messageTemplate->update($request->validated());

        return response()->json(['data' => new MessageTemplateResource($messageTemplate)]);
    }

    public function destroy(Request $request, MessageTemplate $messageTemplate): JsonResponse
    {
        abort_unless($this->scope->userCanAccess($messageTemplate, $request->user()), 403);

        $messageTemplate->delete();

        return response()->json(['message' => 'Template removido com sucesso.']);
    }
}
