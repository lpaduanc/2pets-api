<?php

namespace App\Http\Controllers\Api\Crm;

use App\DataTransferObjects\Crm\CrmMessageRequest;
use App\Enums\MessageCategory;
use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreClientMessageRequest;
use App\Http\Resources\Crm\MessageDispatchResource;
use App\Models\MessageDispatch;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Crm\CrmMessageDispatcher;
use App\Services\Professional\ProfessionalClientsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

/**
 * Aba "Mensagens" da ficha do cliente — contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. `messageHistory` reusa o mesmo
 * "é cliente de" de `ProfessionalClientController::invite()`; `store` monta um
 * `MessageTemplate` transitório (nunca persistido) para reaproveitar o mesmo
 * `CrmMessageDispatcher` de automação/campanha, sem duplicar a checagem de consentimento.
 */
class ClientMessageController extends Controller
{
    public function __construct(
        private readonly ProfessionalClientsQuery $clientsQuery,
        private readonly CommercialScopeResolver $scope,
        private readonly CrmMessageDispatcher $dispatcher,
    ) {}

    public function history(string $id): AnonymousResourceCollection
    {
        $client = $this->clientsQuery->query(Auth::id())->where('id', $id)->firstOrFail();

        $dispatches = $this->scope->scopeQuery(MessageDispatch::query(), Auth::user())
            ->where('client_id', $client->id)
            ->orderByDesc('created_at')
            ->paginate(30);

        return MessageDispatchResource::collection($dispatches);
    }

    public function store(StoreClientMessageRequest $request, string $id): JsonResponse
    {
        $client = $this->clientsQuery->query(Auth::id())->where('id', $id)->firstOrFail();
        $data = $request->validated();

        $dispatch = $this->dispatcher->dispatch(new CrmMessageRequest(
            template: $this->transientTemplate($request->user(), $data),
            client: $client,
            pet: null,
            placeholders: ['client_name' => $client->name, 'professional_name' => $request->user()->name],
        ));

        return response()->json(['data' => $dispatch ? new MessageDispatchResource($dispatch) : null], 201);
    }

    /** @param  array{channel: string, category?: string, subject?: string, body: string}  $data */
    private function transientTemplate(User $professional, array $data): MessageTemplate
    {
        $ownership = $this->scope->ownershipFor($professional);

        return MessageTemplate::make([
            'organization_id' => $ownership['organization_id'],
            'professional_id' => $ownership['professional_id'],
            'name' => 'Mensagem avulsa',
            'channel' => NotificationChannel::from($data['channel']),
            'category' => MessageCategory::from($data['category'] ?? 'transactional'),
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
        ]);
    }
}
