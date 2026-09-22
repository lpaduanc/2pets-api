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
use App\Services\Appointment\AppointmentServicesWriter;
use App\Services\Appointment\AppointmentStatusTransitionService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    use PaginatesResults;

    /**
     * The agenda screen renders the full list and also feeds the invoice and
     * medical record form selects from it, so the default page covers a
     * professional's whole agenda.
     */
    private const DEFAULT_PER_PAGE = 200;

    public function __construct(
        private readonly AppointmentStatusTransitionService $statusTransitionService,
        private readonly AppointmentServicesWriter $servicesWriter,
    ) {}

    public function index(Request $request)
    {
        $query = Appointment::with(['client', 'pet', 'professional', 'appointmentType', 'invoice:id,appointment_id'])
            ->forProfessional($request->user()->id);

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

        return (new AppointmentResource($appointment->load(['client', 'pet', 'services.service', 'appointmentType', 'invoice:id,appointment_id'])))
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
        $appointment = Appointment::with(['client', 'pet', 'professional', 'medicalRecords', 'prescriptions', 'vaccinations', 'services.service', 'appointmentType', 'invoice:id,appointment_id'])
            ->where('professional_id', $request->user()->id)
            ->findOrFail($id);

        return new AppointmentResource($appointment);
    }

    public function update(UpdateAppointmentRequest $request, $id)
    {
        $appointment = Appointment::where('professional_id', $request->user()->id)->findOrFail($id);
        $data = $request->validated();
        $services = $data['services'] ?? null;
        unset($data['services']);

        if ($services !== null) {
            $this->assertServicesEditable($appointment);
        }

        if (array_key_exists('status', $data)) {
            $data = $this->statusTransitionService->prepareTransition($appointment, $data);
        }

        $appointment->update($data);

        if ($services !== null) {
            $appointment->update(['price' => $this->servicesWriter->sync($appointment, $services)]);
        }

        return (new AppointmentResource($appointment->load(['client', 'pet', 'services.service', 'appointmentType', 'invoice:id,appointment_id'])))
            ->additional(['message' => 'Consulta atualizada com sucesso!']);
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

    public function destroy(Request $request, $id)
    {
        $appointment = Appointment::where('professional_id', $request->user()->id)->findOrFail($id);
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
