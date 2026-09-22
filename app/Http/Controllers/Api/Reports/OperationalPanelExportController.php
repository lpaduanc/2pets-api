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
use App\Services\Reports\BulkContactExportAuditor;
use App\Services\Reports\ClinicEventFeedService;
use App\Services\Reports\ImmunizationAdherenceService;
use App\Support\Csv\CsvDownloadResponder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `POST reports/{immunization,birthdays,clinic-events}/export` — contrato
 * `docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md`, regra de negócio 4: exportação
 * de contato em massa exige `clients.contact.view-bulk` (403 sem ela, nunca CSV degradado sem
 * contato) e fica registrada em `activity_log` com quantidade de linhas e filtro usado.
 */
class OperationalPanelExportController extends Controller
{
    private const CONTACT_PERMISSION = 'clients.contact.view-bulk';

    public function __construct(
        private readonly ImmunizationAdherenceService $immunizationService,
        private readonly BirthdayService $birthdayService,
        private readonly ClinicEventFeedService $feedService,
        private readonly BulkContactExportAuditor $auditor,
        private readonly CsvDownloadResponder $csv,
    ) {}

    public function immunization(ImmunizationPanelRequest $request): StreamedResponse
    {
        $this->authorizeExport($request);
        $data = $request->validated();

        $filters = new ImmunizationPanelFilters(
            type: $data['type'] ?? 'vaccine',
            status: $data['status'] ?? null,
            from: isset($data['from']) ? Carbon::parse($data['from']) : null,
            to: isset($data['to']) ? Carbon::parse($data['to']) : null,
            includeContact: true,
        );

        $items = $this->immunizationService->summarize($request->user(), $filters)['items'];
        $rows = $items->map(fn (array $item): array => [
            $item['pet_name'], $item['label'], $item['level'], $item['tutor']['name'], $item['tutor']['phone'], $item['tutor']['email'],
        ]);

        $this->auditor->record($request->user(), 'immunization', $rows->count(), $data);

        return $this->csv->stream('vacinacao.csv', ['pet', 'item', 'status', 'tutor', 'telefone', 'email'], $rows);
    }

    public function birthdays(BirthdayPanelRequest $request): StreamedResponse
    {
        $this->authorizeExport($request);
        $data = $request->validated();

        $filters = new BirthdayFilters(
            from: isset($data['from']) ? Carbon::parse($data['from']) : now(),
            to: isset($data['to']) ? Carbon::parse($data['to']) : now()->addDays(30),
            scope: $data['scope'] ?? 'both',
            includeContact: true,
        );

        $panel = $this->birthdayService->inPeriod($request->user(), $filters);
        $rows = $panel['pets']->concat($panel['clients'])->map(fn (array $item): array => $this->birthdayRow($item));

        $this->auditor->record($request->user(), 'birthdays', $rows->count(), $data);

        return $this->csv->stream('aniversariantes.csv', ['tipo', 'nome', 'aniversario', 'contato_nome', 'telefone', 'email'], $rows);
    }

    public function clinicEvents(ClinicEventFeedRequest $request): StreamedResponse
    {
        $this->authorizeExport($request);
        $data = $request->validated();

        $filters = new ClinicEventFeedFilters(
            clientId: $data['client_id'] ?? null,
            species: $data['species'] ?? null,
            from: isset($data['from']) ? Carbon::parse($data['from']) : now()->subDays(7),
            to: isset($data['to']) ? Carbon::parse($data['to']) : now(),
            includeContact: true,
        );

        $items = collect($this->feedService->forTeam($request->user(), $data['event_type'] ?? [], $filters)['items']);
        $rows = $items->map(fn (array $item): array => [
            $item['type'], $item['date'], $item['pet']['name'], $item['client']['name'], $item['client']['phone'] ?? null,
        ]);

        $this->auditor->record($request->user(), 'clinic-events', $rows->count(), $data);

        return $this->csv->stream('eventos-clinica.csv', ['tipo', 'data', 'pet', 'cliente', 'telefone'], $rows);
    }

    /**
     * Linha de aniversariante — para `type = pet`, o "contato" é o tutor (`tutor.*`); para
     * `type = client`, o contato é o próprio registro (contactBlock nasce achatado no item).
     *
     * @param  array<string, mixed>  $item
     * @return list<string|null>
     */
    private function birthdayRow(array $item): array
    {
        $contact = $item['tutor'] ?? $item;

        return [$item['type'], $item['name'] ?? null, $item['birthday'], $contact['name'] ?? null, $contact['phone'] ?? null, $contact['email'] ?? null];
    }

    private function authorizeExport(Request $request): void
    {
        abort_unless(
            $request->user()->can(self::CONTACT_PERMISSION),
            403,
            'Você não tem permissão para exportar contato de clientes em massa.'
        );
    }
}
