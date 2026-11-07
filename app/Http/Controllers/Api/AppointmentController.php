<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Appointment\StoreAppointmentRequest;
use App\Http\Requests\Appointment\UpdateAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\SoldPackage;
use App\Services\Appointment\AppointmentServicesWriter;
use App\Services\Appointment\AppointmentStatusTransitionService;
use App\Services\Appointment\Recurrence\AppointmentSeriesService;
use App\Services\Transport\PetTransportService;
use App\Services\Transport\TransportQuoteService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    use PaginatesResults;

    /**
     * The agenda screen renders the full list and also feeds the invoice and
     * medical record form selects from it, so the default page covers a
     * professional's whole agenda.
     */
    private const DEFAULT_PER_PAGE = 200;

    /** PLANO 3.1: campos que, mudados em "só este", tornam a ocorrência uma exceção da série. */
    private const EXCEPTION_FIELDS = ['appointment_date', 'appointment_time', 'duration', 'type', 'appointment_type_id', 'reason', 'notes', 'price', 'sold_package_id'];

    private const RESPONSE_RELATIONS = ['client', 'pet', 'services.service', 'appointmentType', 'invoice:id,appointment_id', 'series'];

    public function __construct(
        private readonly AppointmentStatusTransitionService $statusTransitionService,
        private readonly AppointmentServicesWriter $servicesWriter,
        private readonly AppointmentSeriesService $series,
        private readonly PetTransportService $transports,
        private readonly TransportQuoteService $transportQuotes,
    ) {}

    public function index(Request $request)
    {
        $query = Appointment::with(['client', 'pet', 'professional', 'appointmentType', 'invoice:id,appointment_id', 'series'])
            ->forProfessional($request->user()->id);

        // PLANO 3.1: só ocorrências de série ("Recorrentes").
        if ($request->boolean('recurring')) {
            $query->whereNotNull('series_id');
        }

        // Filters
        if ($request->has('date')) {
            // Range instead of whereDate(): appointment_date is a datetime column,
            // so whereDate() would cast it and block index usage.
            $filterDate = Carbon::parse($request->date)->startOfDay();
            $query->where('appointment_date', '>=', $filterDate)
                ->where('appointment_date', '<', $filterDate->copy()->addDay());
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $appointments = $query->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        return AppointmentResource::collection($appointments);
    }

    public function store(StoreAppointmentRequest $request)
    {
        $data = $request->validated();
        $recurrence = $data['recurrence'] ?? null;
        $resolutions = $data['resolutions'] ?? [];
        $transport = $data['transport'] ?? null;
        unset($data['recurrence'], $data['resolutions'], $data['transport']);
        $organizationId = $request->user()->activeOrganizationId();

        // PLANO 3.7: endereço fora da área recusa ANTES de criar qualquer agendamento.
        if ($transport !== null) {
            $this->transportQuotes->quote($organizationId, $request->user()->id, $transport['address']);
        }

        return DB::transaction(fn () => $this->createAppointments($request, $data, $recurrence, $resolutions, $transport, $organizationId));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $recurrence
     * @param  array<int, mixed>  $resolutions
     * @param  array{type: string, address: array<string, string|null>}|null  $transport
     */
    private function createAppointments(StoreAppointmentRequest $request, array $data, ?array $recurrence, array $resolutions, ?array $transport, ?int $organizationId)
    {
        // PLANO 3.1: com `recurrence`, cria a série (409 com os conflitos, se houver).
        if ($recurrence !== null) {
            $first = $this->series->create($request->user(), $data + ['organization_id' => $organizationId], $recurrence, $resolutions);

            // PLANO 3.7: o leva e traz vale para cada ocorrência da série (mesmo endereço).
            if ($transport !== null) {
                Appointment::where('series_id', $first->series_id)->orderBy('id')->get()
                    ->each(fn (Appointment $occurrence) => $this->transports->attach($occurrence, $transport, $organizationId));
            }

            return (new AppointmentResource($first->load(self::RESPONSE_RELATIONS)))
                ->additional([
                    'message' => 'Agendamentos recorrentes criados com sucesso!',
                    'occurrences_created' => Appointment::where('series_id', $first->series_id)->count(),
                ])
                ->response()
                ->setStatusCode(201);
        }

        $services = $data['services'] ?? null;
        unset($data['services']);

        $data['professional_id'] = $request->user()->id;
        $data['status'] = AppointmentStatus::SCHEDULED->value;
        // Explícito em vez de depender do DEFAULT da coluna — contrato §E: o DEFAULT
        // mascarava a intenção de toda linha criada por este controller.
        $data['booking_source'] = BookingSource::PROFESSIONAL->value;
        $data['price'] = $this->resolveEstimatedPrice($data);

        $appointment = Appointment::create($data);

        if ($services !== null) {
            $appointment->update(['price' => $this->servicesWriter->sync($appointment, $services)]);
        }

        if ($transport !== null) {
            $this->transports->attach($appointment, $transport, $organizationId);
        }

        return (new AppointmentResource($appointment->load(['client', 'pet', 'services.service', 'appointmentType', 'invoice:id,appointment_id', 'transport'])))
            ->additional(['message' => 'Consulta agendada com sucesso!'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.2: quando
     * o profissional envia `services[]`, o preço estimado passa a ser a SOMA dessa pivô
     * (`AppointmentServicesWriter::sync`) — este método só resolve o caminho LEGADO de
     * `service_id`/`price` únicos (deprecado, não removido), preservado para quem ainda
     * não migrou para `services[]`.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveEstimatedPrice(array $data): ?float
    {
        if (isset($data['price'])) {
            return $data['price'];
        }

        if (! isset($data['service_id'])) {
            return null;
        }

        return Service::find($data['service_id'])?->price;
    }

    public function show(Request $request, $id)
    {
        $appointment = Appointment::with(['client', 'pet', 'professional', 'medicalRecords', 'prescriptions', 'vaccinations', 'services.service', 'appointmentType', 'invoice:id,appointment_id', 'series'])
            ->where('professional_id', $request->user()->id)
            ->findOrFail($id);

        return new AppointmentResource($appointment);
    }

    public function update(UpdateAppointmentRequest $request, $id)
    {
        $appointment = Appointment::where('professional_id', $request->user()->id)->findOrFail($id);
        $data = $request->validated();
        $scope = $data['scope'] ?? 'this';
        $recurrence = $data['recurrence'] ?? null;
        $resolutions = $data['resolutions'] ?? [];
        unset($data['scope'], $data['recurrence'], $data['resolutions']);

        // PLANO 3.1: "este e os seguintes" em ocorrência de série.
        if ($appointment->series_id !== null && $scope === 'this_and_following') {
            return $this->updateFollowing($appointment, $data, $recurrence, $resolutions);
        }

        $services = $data['services'] ?? null;
        unset($data['services']);

        if ($services !== null) {
            $this->assertServicesEditable($appointment);
        }

        // "Só este": mudar data, hora ou o conteúdo da ocorrência a torna exceção da série.
        if ($appointment->series_id !== null && ($services !== null || $this->changesOccurrence($appointment, $data))) {
            $data['is_exception'] = true;
        }

        // PLANO 2.7: só pacote do mesmo cliente do agendamento.
        if (($data['sold_package_id'] ?? null) !== null && SoldPackage::whereKey($data['sold_package_id'])->value('client_id') !== $appointment->client_id) {
            throw ValidationException::withMessages(['sold_package_id' => 'Este pacote não é do cliente deste agendamento.']);
        }

        if (array_key_exists('status', $data)) {
            $data = $this->statusTransitionService->prepareTransition($appointment, $data);
        }

        $appointment->update($data);

        if ($services !== null) {
            $appointment->update(['price' => $this->servicesWriter->sync($appointment, $services)]);
        }

        return (new AppointmentResource($appointment->load(self::RESPONSE_RELATIONS)))
            ->additional(['message' => 'Consulta atualizada com sucesso!']);
    }

    /**
     * "Este e os seguintes": cancelar encerra a série na ocorrência anterior e cancela daqui em
     * diante; qualquer outra mudança divide a série (esta vira a 1ª de uma série nova).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $recurrence
     * @param  list<array<string, mixed>>  $resolutions
     */
    private function updateFollowing(Appointment $appointment, array $data, ?array $recurrence, array $resolutions): AppointmentResource
    {
        if (($data['status'] ?? null) === AppointmentStatus::CANCELLED->value) {
            $count = $this->series->truncateFrom($appointment, cancel: true);

            return (new AppointmentResource($appointment->fresh()->load(self::RESPONSE_RELATIONS)))
                ->additional(['message' => 'Ocorrências canceladas a partir desta.', 'occurrences_affected' => $count]);
        }

        unset($data['status']);
        $updated = $this->series->splitFrom($appointment, $data, $recurrence, $resolutions);

        return (new AppointmentResource($updated->load(self::RESPONSE_RELATIONS)))
            ->additional(['message' => 'Esta e as próximas ocorrências foram atualizadas.']);
    }

    /** @param  array<string, mixed>  $data */
    private function changesOccurrence(Appointment $appointment, array $data): bool
    {
        foreach (self::EXCEPTION_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $current = match ($field) {
                'appointment_date' => $appointment->appointment_date?->toDateString(),
                'appointment_time' => $appointment->appointment_time?->format('H:i'),
                default => $appointment->getAttribute($field),
            };
            $incoming = $field === 'appointment_date' ? substr((string) $data[$field], 0, 10) : $data[$field];

            if ((string) $current !== (string) $incoming) {
                return true;
            }
        }

        return false;
    }

    /**
     * Achado do contrato docs/atendimento-veterinario/
     * 11-internacao-no-fluxo-de-faturamento.md §0/§6: uma vez que a fatura do agendamento já
     * existe, editar `services[]` por aqui deixava essa fatura desatualizada em silêncio
     * (`AppointmentServicesWriter::sync()` não sincroniza fatura nenhuma). A partir daqui,
     * mudança de item depois que a conta já nasceu é sempre via `/charges`
     * (`AppointmentChargeService`, que já sincroniza a cada mutação).
     */
    private function assertServicesEditable(Appointment $appointment): void
    {
        if (Invoice::where('appointment_id', $appointment->id)->exists()) {
            abort(422, 'Este agendamento já tem uma fatura em aberto — edite os serviços pela conta do atendimento (/charges), não por aqui.');
        }
    }

    /**
     * PLANO 3.1: em ocorrência de série, `?scope=this` (padrão) remove só esta (fica como exceção
     * apagada, o job não a recria) e `?scope=this_and_following` encerra a série na anterior e
     * remove as próximas que ainda não aconteceram.
     */
    public function destroy(Request $request, $id)
    {
        $appointment = Appointment::where('professional_id', $request->user()->id)->findOrFail($id);
        $scope = $request->validate(['scope' => ['nullable', 'in:this,this_and_following']])['scope'] ?? 'this';

        if ($appointment->series_id !== null && $scope === 'this_and_following') {
            $count = $this->series->truncateFrom($appointment, cancel: false);

            return response()->json(['message' => 'Ocorrências removidas a partir desta.', 'occurrences_affected' => $count]);
        }

        if ($appointment->series_id !== null) {
            $appointment->update(['is_exception' => true]);
        }

        $appointment->delete();

        return response()->json(['message' => 'Consulta removida com sucesso!']);
    }

    public function today(Request $request)
    {
        $appointments = Appointment::with(['client', 'pet', 'appointmentType', 'invoice:id,appointment_id'])
            ->forProfessional($request->user()->id)
            ->today()
            ->orderBy('appointment_time')
            ->get();

        return AppointmentResource::collection($appointments);
    }

    public function upcoming(Request $request)
    {
        $appointments = Appointment::with(['client', 'pet', 'appointmentType', 'invoice:id,appointment_id'])
            ->forProfessional($request->user()->id)
            ->upcoming()
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->limit(10)
            ->get();

        return AppointmentResource::collection($appointments);
    }
}
