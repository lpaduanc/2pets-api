<?php

namespace App\Http\Controllers\Api\Transport;

use App\Enums\PetTransportStatus;
use App\Http\Controllers\Controller;
use App\Models\OrganizationMember;
use App\Models\PetTransport;
use App\Services\Transport\PetTransportQueryService;
use App\Services\Transport\PetTransportService;
use App\Services\Transport\TransportQuoteService;
use App\Support\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Leva e traz (docs/gap-petflow/PLANO.md, 3.7): lista do dia, cotação, status e KPIs. Permissão
 * no padrão da agenda (rotas): ler com `agenda.view.own|any`, mudar status com `agenda.manage`.
 * Escopo da organização ativa (`PetTransportQueryService::scoped`): outra organização → 404.
 */
class PetTransportController extends Controller
{
    public function __construct(
        private readonly PetTransportQueryService $query,
        private readonly PetTransportService $transports,
        private readonly TransportQuoteService $quotes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $rows = $this->query->day($request->user(), $data['date'] ?? BusinessClock::todayString());

        return response()->json(['data' => $rows->map(fn (PetTransport $t): array => $this->present($t))->values()]);
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cep' => ['nullable', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'address' => ['nullable'],
            'zip_code' => ['nullable', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'complement' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'size:2'],
        ]);
        $address = $this->addressFrom($data);
        $user = $request->user();
        $quote = $this->quotes->quote($user->activeOrganizationId(), $user->id, $address);

        return response()->json(['data' => ['zone' => ['id' => $quote['zone']->id, 'name' => $quote['zone']->name], 'distance_km' => $quote['distance_km'], 'fee' => $quote['fee']]]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(PetTransportStatus::class)],
            'driver_id' => ['nullable', 'integer'],
        ]);
        $transport = $this->query->scoped($request->user())->findOrFail($id);
        $driverId = isset($data['driver_id']) ? $this->driver($transport, (int) $data['driver_id']) : null;

        return response()->json(['data' => $this->present($this->transports->changeStatus($transport, PetTransportStatus::from($data['status']), $driverId))]);
    }

    public function kpis(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json(['data' => $this->query->kpis($request->user(), $data['from'], $data['to'])]);
    }

    /**
     * Aceita o endereço "achatado" (o que o front manda), `address` como objeto ou só `cep`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function addressFrom(array $data): array
    {
        $address = is_array($data['address'] ?? null) ? $data['address'] : [
            'address' => is_string($data['address'] ?? null) ? $data['address'] : null,
            'zip_code' => $data['zip_code'] ?? null,
            'complement' => $data['complement'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
        ];
        $address['zip_code'] = ($address['zip_code'] ?? null) ?: ($data['cep'] ?? null);

        if (empty($address['zip_code']) && empty($address['address'])) {
            throw ValidationException::withMessages(['address' => 'Informe o CEP ou o endereço.']);
        }

        return array_map(fn ($value) => is_string($value) ? $value : null, array_intersect_key($address, array_flip(['zip_code', 'address', 'complement', 'city', 'state'])));
    }

    /** Motorista = membro ativo da mesma organização do transporte. */
    private function driver(PetTransport $transport, int $userId): int
    {
        $isMember = $transport->organization_id === null
            ? $userId === $transport->professional_id
            : OrganizationMember::where('organization_id', $transport->organization_id)->where('user_id', $userId)->exists();

        if (! $isMember) {
            throw ValidationException::withMessages(['driver_id' => 'Motorista não encontrado na equipe.']);
        }

        return $userId;
    }

    /** @return array<string, mixed> */
    private function present(PetTransport $transport): array
    {
        $appointment = $transport->appointment;

        return [
            'id' => $transport->id,
            'appointment_id' => $transport->appointment_id,
            'type' => $transport->type->value,
            'type_label' => $transport->type->label(),
            'address' => ['zip_code' => $transport->zip_code, 'address' => $transport->address, 'complement' => $transport->complement, 'city' => $transport->city, 'state' => $transport->state],
            'zone' => $transport->zone === null ? null : ['id' => $transport->zone->id, 'name' => $transport->zone->name],
            'distance_km' => $transport->distance_km === null ? null : (string) $transport->distance_km,
            'fee' => (string) $transport->fee,
            'driver_id' => $transport->driver_id,
            'driver' => $transport->driver === null ? null : ['id' => $transport->driver->id, 'name' => $transport->driver->name],
            'pet' => $appointment?->pet === null ? null : ['id' => $appointment->pet->id, 'name' => $appointment->pet->name],
            'client' => $appointment?->client === null ? null : ['id' => $appointment->client->id, 'name' => $appointment->client->name],
            'status' => $transport->status->value,
            'status_label' => $transport->status->label(),
            'scheduled_pickup_at' => $transport->scheduled_pickup_at?->toIso8601String(),
            'on_the_way_at' => $transport->on_the_way_at?->toIso8601String(),
            'picked_up_at' => $transport->picked_up_at?->toIso8601String(),
            'delivered_at' => $transport->delivered_at?->toIso8601String(),
            'failed_at' => $transport->failed_at?->toIso8601String(),
            'cancelled_at' => $transport->cancelled_at?->toIso8601String(),
        ];
    }
}
