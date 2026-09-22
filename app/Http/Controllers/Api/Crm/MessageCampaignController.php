<?php

namespace App\Http\Controllers\Api\Crm;

use App\Enums\MessageCampaignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreMessageCampaignRequest;
use App\Http\Resources\Crm\MessageCampaignResource;
use App\Models\ClientSegment;
use App\Models\MessageCampaign;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Crm\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** `message-campaigns` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. */
class MessageCampaignController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly CampaignService $campaigns,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $campaigns = $this->scope->scopeQuery(MessageCampaign::query(), $request->user())
            ->with('template')
            ->orderByDesc('created_at')
            ->get();

        return MessageCampaignResource::collection($campaigns);
    }

    public function store(StoreMessageCampaignRequest $request): JsonResponse
    {
        $campaign = MessageCampaign::create($request->validated() + $this->scope->ownershipFor($request->user()) + [
            'status' => MessageCampaignStatus::DRAFT,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => new MessageCampaignResource($campaign->load('template'))], 201);
    }

    public function show(Request $request, MessageCampaign $messageCampaign): MessageCampaignResource
    {
        abort_unless($this->scope->userCanAccess($messageCampaign, $request->user()), 403);

        return new MessageCampaignResource($messageCampaign->load('template'));
    }

    public function destroy(Request $request, MessageCampaign $messageCampaign): JsonResponse
    {
        abort_unless($this->scope->userCanAccess($messageCampaign, $request->user()), 403);

        $messageCampaign->delete();

        return response()->json(['message' => 'Campanha removida com sucesso.']);
    }

    /** `POST message-campaigns/preview { segment_id }` — antes de criar a campanha. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['segment_id' => ['required', 'integer', 'exists:client_segments,id']]);
        $segment = ClientSegment::findOrFail($data['segment_id']);

        abort_unless($this->scope->userCanAccess($segment, $request->user()), 403);

        return response()->json(['data' => $this->campaigns->previewSegment($request->user(), $segment)]);
    }

    public function send(Request $request, MessageCampaign $messageCampaign): JsonResponse
    {
        abort_unless($this->scope->userCanAccess($messageCampaign, $request->user()), 403);
        abort_if($messageCampaign->status->isFinal(), 422, 'Campanha já foi finalizada.');

        $sent = $this->campaigns->dispatch($messageCampaign->load('template'));

        return response()->json(['data' => new MessageCampaignResource($sent)]);
    }

    public function cancel(Request $request, MessageCampaign $messageCampaign): JsonResponse
    {
        abort_unless($this->scope->userCanAccess($messageCampaign, $request->user()), 403);
        abort_if($messageCampaign->status->isFinal(), 422, 'Campanha já foi finalizada.');

        return response()->json(['data' => new MessageCampaignResource($this->campaigns->cancel($messageCampaign))]);
    }
}
