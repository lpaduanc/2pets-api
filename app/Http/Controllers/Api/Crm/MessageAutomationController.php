<?php

namespace App\Http\Controllers\Api\Crm;

use App\DataTransferObjects\Crm\CrmMessageRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreMessageAutomationRequest;
use App\Http\Resources\Crm\MessageAutomationResource;
use App\Models\MessageAutomation;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Crm\CrmMessageDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** `message-automations` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. */
class MessageAutomationController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly CrmMessageDispatcher $dispatcher,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $automations = $this->scope->scopeQuery(MessageAutomation::query(), $request->user())
            ->with('template')
            ->orderByDesc('created_at')
            ->get();

        return MessageAutomationResource::collection($automations);
    }

    public function store(StoreMessageAutomationRequest $request): JsonResponse
    {
        $automation = MessageAutomation::create($request->validated() + $this->scope->ownershipFor($request->user()));

        return response()->json(['data' => new MessageAutomationResource($automation->load('template'))], 201);
    }

    public function update(StoreMessageAutomationRequest $request, MessageAutomation $messageAutomation): JsonResponse
    {
        abort_unless($this->scope->userCanAccess($messageAutomation, $request->user()), 403);

        $messageAutomation->update($request->validated());

        return response()->json(['data' => new MessageAutomationResource($messageAutomation->load('template'))]);
    }

    public function destroy(Request $request, MessageAutomation $messageAutomation): JsonResponse
    {
        abort_unless($this->scope->userCanAccess($messageAutomation, $request->user()), 403);

        $messageAutomation->delete();

        return response()->json(['message' => 'Automação removida com sucesso.']);
    }

    /**
     * Envia só para o profissional logado — contrato: "botão testar envio para mim". Não
     * conta como dedup de automação de verdade (`automation: null` no request de envio), é só
     * um teste manual do template/canal.
     */
    public function test(Request $request, MessageAutomation $messageAutomation): JsonResponse
    {
        abort_unless($this->scope->userCanAccess($messageAutomation, $request->user()), 403);

        $dispatch = $this->dispatcher->dispatch(new CrmMessageRequest(
            template: $messageAutomation->template,
            client: $request->user(),
            pet: null,
            placeholders: ['client_name' => $request->user()->name],
        ));

        return response()->json(['message' => 'Teste enviado.', 'status' => $dispatch?->status->value]);
    }
}
