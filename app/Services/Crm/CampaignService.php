<?php

namespace App\Services\Crm;

use App\DataTransferObjects\Crm\CrmMessageRequest;
use App\Enums\MessageCampaignStatus;
use App\Enums\MessageDispatchStatus;
use App\Models\ClientSegment;
use App\Models\MessageCampaign;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * `preview`/`dispatch`/`cancel` de campanha — contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`, item 5 do escopo. Consome
 * `ClientSegmentQueryBuilder` de 18 (segmento salvo) ou `ad_hoc_client_ids` (lista pontual de
 * 25, "estas 12 linhas selecionadas agora").
 */
final class CampaignService
{
    private const PREVIEW_SAMPLE_SIZE = 5;

    public function __construct(
        private readonly ClientSegmentQueryBuilder $segmentQueryBuilder,
        private readonly CrmMessageDispatcher $dispatcher,
    ) {}

    /**
     * `POST message-campaigns/preview { segment_id }` — antes de qualquer campanha existir,
     * a UI mostra quantos clientes o segmento alcança agora.
     *
     * @return array{recipients_count: int, sample: list<array{id: int, name: string}>}
     */
    public function previewSegment(User $professional, ClientSegment $segment): array
    {
        return $this->summarize($this->recipientsForSegment($professional, $segment));
    }

    /** @param  Collection<int, User>  $recipients @return array{recipients_count: int, sample: list<array{id: int, name: string}>} */
    private function summarize(Collection $recipients): array
    {
        return [
            'recipients_count' => $recipients->count(),
            'sample' => $recipients->take(self::PREVIEW_SAMPLE_SIZE)
                ->map(fn (User $client): array => ['id' => $client->id, 'name' => $client->name])
                ->values()
                ->all(),
        ];
    }

    public function dispatch(MessageCampaign $campaign): MessageCampaign
    {
        $recipients = $this->recipients($campaign);
        $campaign->forceFill(['status' => MessageCampaignStatus::SENDING, 'recipients_count' => $recipients->count()])->save();

        [$sentCount, $failedCount] = $this->dispatchToEach($campaign, $recipients);

        $campaign->forceFill([
            'status' => MessageCampaignStatus::SENT,
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
        ])->save();

        return $campaign;
    }

    public function cancel(MessageCampaign $campaign): MessageCampaign
    {
        $campaign->forceFill(['status' => MessageCampaignStatus::CANCELLED])->save();

        return $campaign;
    }

    /** @return array{0: int, 1: int} */
    private function dispatchToEach(MessageCampaign $campaign, Collection $recipients): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($recipients as $client) {
            $result = $this->dispatcher->dispatch($this->requestFor($campaign, $client));
            $result?->status === MessageDispatchStatus::SENT ? $sent++ : $failed++;
        }

        return [$sent, $failed];
    }

    private function requestFor(MessageCampaign $campaign, User $client): CrmMessageRequest
    {
        return new CrmMessageRequest(
            template: $campaign->template,
            client: $client,
            pet: null,
            placeholders: ['client_name' => $client->name],
            campaign: $campaign,
        );
    }

    /** @return Collection<int, User> */
    private function recipients(MessageCampaign $campaign): Collection
    {
        if ($campaign->client_segment_id !== null) {
            return $this->recipientsForSegment($campaign->professional, $campaign->segment);
        }

        return User::query()->whereIn('id', $campaign->ad_hoc_client_ids ?? [])->get();
    }

    /** @return Collection<int, User> */
    private function recipientsForSegment(User $professional, ClientSegment $segment): Collection
    {
        return $this->segmentQueryBuilder
            ->build($professional, $segment->definition)
            ->with('client')
            ->get()
            ->pluck('client')
            ->filter()
            ->values();
    }
}
