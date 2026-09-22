<?php

namespace App\Http\Controllers\Api\Reports;

use App\DataTransferObjects\Reports\BirthdayFilters;
use App\DataTransferObjects\Reports\ClinicEventFeedFilters;
use App\DataTransferObjects\Reports\ImmunizationPanelFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\BirthdayPanelRequest;
use App\Http\Requests\Reports\ClinicEventFeedRequest;
use App\Http\Requests\Reports\ImmunizationPanelRequest;
use App\Services\Reports\BirthdayService;
use App\Services\Reports\ClinicEventFeedService;
use App\Services\Reports\ImmunizationAdherenceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Painéis operacionais (vacinação, aniversários, feed de eventos) — contrato
 * `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`. Controller só traduz
 * request → filtro → service → resposta; toda regra mora nos services.
 */
class OperationalPanelController extends Controller
{
    private const CONTACT_PERMISSION = 'clients.contact.view-bulk';

    private const DEFAULT_WINDOW_DAYS = 30;

    private const DEFAULT_FEED_WINDOW_DAYS = 7;

    public function __construct(
        private readonly ImmunizationAdherenceService $immunizationService,
        private readonly BirthdayService $birthdayService,
        private readonly ClinicEventFeedService $feedService,
    ) {}

    public function immunization(ImmunizationPanelRequest $request): JsonResponse
    {
        $data = $request->validated();

        $filters = new ImmunizationPanelFilters(
            type: $data['type'] ?? 'vaccine',
            status: $data['status'] ?? null,
            from: isset($data['from']) ? Carbon::parse($data['from']) : null,
            to: isset($data['to']) ? Carbon::parse($data['to']) : null,
            includeContact: $this->includeContact($request),
        );

        return response()->json(['data' => $this->immunizationService->summarize($request->user(), $filters)]);
    }

    public function birthdays(BirthdayPanelRequest $request): JsonResponse
    {
        $data = $request->validated();

        $filters = new BirthdayFilters(
            from: isset($data['from']) ? Carbon::parse($data['from']) : now(),
            to: isset($data['to']) ? Carbon::parse($data['to']) : now()->addDays(self::DEFAULT_WINDOW_DAYS),
            scope: $data['scope'] ?? 'both',
            includeContact: $this->includeContact($request),
        );

        return response()->json(['data' => $this->birthdayService->inPeriod($request->user(), $filters)]);
    }

    public function clinicEvents(ClinicEventFeedRequest $request): JsonResponse
    {
        $data = $request->validated();

        $filters = new ClinicEventFeedFilters(
            clientId: $data['client_id'] ?? null,
            species: $data['species'] ?? null,
            from: isset($data['from']) ? Carbon::parse($data['from']) : now()->subDays(self::DEFAULT_FEED_WINDOW_DAYS),
            to: isset($data['to']) ? Carbon::parse($data['to']) : now(),
            includeContact: $this->includeContact($request),
        );

        $eventTypes = $data['event_type'] ?? [];

        return response()->json(['data' => $this->feedService->forTeam($request->user(), $eventTypes, $filters)]);
    }

    /**
     * Regra de negócio 4 da spec: telefone/e-mail em massa exigem `clients.contact.view-bulk`
     * — sem a permissão, os 3 painéis continuam funcionando, só sem o bloco de contato.
     */
    private function includeContact(Request $request): bool
    {
        return $request->user()->can(self::CONTACT_PERMISSION);
    }
}
