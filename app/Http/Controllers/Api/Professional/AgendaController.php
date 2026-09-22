<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agenda\AgendaQueueRequest;
use App\Http\Requests\Agenda\DayAgendaRequest;
use App\Http\Resources\AppointmentResource;
use App\Services\Booking\AppointmentQueueService;
use App\Services\Booking\DayAgendaService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * Leituras agregadas de agenda — item 21 do backlog gap-simplesvet
 * (`docs/gap-simplesvet/specs/21-agenda-escala-bloqueios-recursos-spec.md`). Só leitura: a
 * escrita de agendamento continua em `AppointmentController`/`ConsultationController`.
 */
class AgendaController extends Controller
{
    public function __construct(
        private readonly DayAgendaService $dayAgendaService,
        private readonly AppointmentQueueService $queueService,
    ) {}

    /** GET professional/agenda/day?date=&location_id=&area_id= */
    public function day(DayAgendaRequest $request): JsonResponse
    {
        $date = Carbon::createFromFormat('Y-m-d', $request->validated('date'));

        $resources = $this->dayAgendaService->build(
            $request->user(),
            $date,
            $request->locationId(),
            $request->areaId(),
        );

        return response()->json([
            'date' => $date->toDateString(),
            'resources' => $resources->map(fn (array $resource): array => [
                'id' => $resource['id'],
                'name' => $resource['name'],
                'role' => $resource['role'],
                'role_label' => $resource['role_label'],
                'appointments' => AppointmentResource::collection($resource['appointments'])->resolve($request),
            ]),
        ]);
    }

    /** GET professional/agenda/queue?date= */
    public function queue(AgendaQueueRequest $request): JsonResponse
    {
        $date = Carbon::createFromFormat('Y-m-d', $request->validated('date'));

        $queue = $this->queueService->forDate($request->user(), $date);

        return response()->json([
            'date' => $date->toDateString(),
            'next' => AppointmentResource::collection($queue['next'])->resolve($request),
            'attended' => AppointmentResource::collection($queue['attended'])->resolve($request),
        ]);
    }
}
