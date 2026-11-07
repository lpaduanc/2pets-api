<?php

namespace Tests\Feature\Transport;

use App\Contracts\GeocodingProvider;
use App\Models\Appointment;
use App\Models\DeliveryZone;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\PetTransport;
use App\Models\User;
use App\Services\Invoice\AppointmentInvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Leva e traz (docs/gap-petflow/PLANO.md, 3.7): CRUD das zonas (`delivery_zones`), zona por faixa de CEP, fallback por distância
 * (Haversine a partir da clínica), fora da área, taxa como item de venda do agendamento, fluxo
 * de status com horários, KPIs e outra organização. Geocodificação por um provedor falso.
 */
class PetTransportTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    /** Clínica na Sé; "+N km" = N km ao norte (1° de latitude ≈ 111,19 km). */
    private const CLINIC = ['latitude' => -23.5505, 'longitude' => -46.6333];

    private const PLACES = [
        'Rua Três Km' => ['latitude' => -23.52352, 'longitude' => -46.6333],
        'Rua Oito Km' => ['latitude' => -23.47855, 'longitude' => -46.6333],
        'Rua Longe' => ['latitude' => -23.10084, 'longitude' => -46.6333],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00:00', 'UTC'));
        $this->app->instance(GeocodingProvider::class, new class(self::PLACES) implements GeocodingProvider
        {
            /** @param  array<string, array{latitude: float, longitude: float}>  $places */
            public function __construct(private readonly array $places) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function geocode(string $address): ?array
            {
                foreach ($this->places as $needle => $coordinates) {
                    if (str_contains($address, $needle)) {
                        return $coordinates;
                    }
                }

                return null;
            }

            public function reverseGeocode(float $latitude, float $longitude): ?array
            {
                return null;
            }
        });
        $this->buildClinic();
        $this->clinic->update(self::CLINIC);
        $this->zone('Centro', ['rule_type' => 'cep', 'ceps' => ['01000000..01999999', '04500-000'], 'fee' => '15.00']);
        $this->zone('Bairro vizinho', ['rule_type' => 'cep', 'ceps' => ['04500-000'], 'fee' => '12.00']);
        $this->zone('Até 5 km', ['rule_type' => 'distance', 'max_km' => 5, 'fee' => '20.00']);
        $this->zone('Até 10 km', ['rule_type' => 'distance', 'max_km' => 10, 'fee' => '30.00']);
    }

    public function test_quote_by_cep_range_then_distance_then_out_of_area(): void
    {
        $this->quote(['zip_code' => '01310-100'])->assertOk()->assertJsonPath('data.zone.name', 'Centro')->assertJsonPath('data.fee', '15.00')->assertJsonPath('data.distance_km', null);
        // Duas zonas com o mesmo CEP: vale a de menor taxa.
        $this->quote(['cep' => '04500000'])->assertOk()->assertJsonPath('data.zone.name', 'Bairro vizinho')->assertJsonPath('data.fee', '12.00');
        // CEP fora das faixas → distância a partir da clínica: 3 km → "Até 5 km"; 8 km → "Até 10 km".
        $this->quote(['zip_code' => '05000-000', 'address' => 'Rua Três Km, 10', 'city' => 'São Paulo', 'state' => 'SP'])->assertOk()
            ->assertJsonPath('data.zone.name', 'Até 5 km')->assertJsonPath('data.distance_km', '3.00')->assertJsonPath('data.fee', '20.00');
        $this->quote(['address' => 'Rua Oito Km, 99', 'city' => 'São Paulo', 'state' => 'SP'])->assertOk()->assertJsonPath('data.zone.name', 'Até 10 km')->assertJsonPath('data.distance_km', '8.00');
        $this->quote(['address' => 'Rua Longe, 1', 'city' => 'Jundiaí', 'state' => 'SP'])->assertUnprocessable()->assertJsonPath('message', 'Endereço fora da área do leva e traz.');
        $this->quote(['address' => 'Lugar que o mapa não acha'])->assertUnprocessable()->assertJsonValidationErrors('address');

        // CRUD das zonas: CEPs normalizados; trocar a regra limpa a outra; regra incompleta → 422.
        $zones = "/api/organizations/{$this->clinic->id}/delivery-zones";
        $centro = DeliveryZone::where('name', 'Centro')->sole();
        $this->owner()->getJson($zones)->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonFragment(['name' => 'Centro', 'rule_type' => 'cep', 'ceps' => ['01000-000..01999-999', '04500-000'], 'max_km' => null, 'fee' => '15.00', 'active' => true]);
        $this->owner()->putJson("{$zones}/{$centro->id}", ['rule_type' => 'distance', 'max_km' => 2])->assertOk()
            ->assertJsonPath('data.ceps', [])->assertJsonPath('data.max_km', '2.00');
        $this->owner()->postJson($zones, ['name' => 'Sem regra', 'rule_type' => 'distance', 'fee' => '5'])->assertUnprocessable()->assertJsonValidationErrors('max_km');
        // Zona inativa não cota: o CEP do Centro cai para a distância e o endereço não geocodifica.
        $this->owner()->putJson("{$zones}/{$centro->id}", ['rule_type' => 'cep', 'ceps' => ['01000-000..01999-999'], 'active' => false])->assertOk();
        $this->quote(['zip_code' => '01310-100'])->assertUnprocessable();
        // Setor (área de atendimento) não tem mais nada de leva e traz.
        $this->assertArrayNotHasKey('rule_type', $this->owner()->postJson("/api/organizations/{$this->clinic->id}/service-areas", ['name' => 'Banho e Tosa'])->assertCreated()->json('data'));
    }

    public function test_fee_becomes_a_service_line_of_the_appointment_and_reaches_the_invoice(): void
    {
        $response = $this->schedule(['type' => 'round_trip', 'address' => ['zip_code' => '01310-100', 'address' => 'Av. Paulista, 1000', 'complement' => 'ap 12', 'city' => 'São Paulo', 'state' => 'sp']])
            ->assertCreated()->assertJsonPath('data.transport.fee', '15.00')->assertJsonPath('data.transport.status', 'pending');
        $appointment = Appointment::findOrFail($response->json('data.id'));
        $transport = PetTransport::sole();
        $this->assertSame(['round_trip', 'SP', '15.00', '2026-11-10 13:00:00'], [$transport->type->value, $transport->state, (string) $transport->fee, $transport->scheduled_pickup_at->utc()->format('Y-m-d H:i:s')]);

        $line = $appointment->services()->with('service')->sole();
        $this->assertSame(['Leva e traz', 'pet_transport', '15.00'], [$line->service->name, $line->service->system_key, (string) $line->unit_price]);
        $this->assertEquals(15.0, (float) $appointment->fresh()->price);

        // Editar os serviços do agendamento não apaga a taxa.
        $this->owner()->putJson("/api/professional/appointments/{$appointment->id}", ['services' => [['service_id' => $this->makeService(['name' => 'Banho', 'price' => 60], by: $this->owner)->id]]])->assertOk();
        $this->assertSame(2, $appointment->services()->count());
        $this->assertEquals(75.0, (float) $appointment->fresh()->price);

        // Ao iniciar o atendimento, a fatura nasce com a taxa como item, igual a qualquer serviço.
        $invoice = app(AppointmentInvoiceService::class)->ensurePendingInvoice($appointment->fresh());
        $this->assertContains(['Leva e traz', 15.0], collect($invoice->items)->map(fn (array $item): array => [$item['description'], (float) $item['total']])->all());

        // Fora da área: 422 e nenhum agendamento a mais.
        $this->schedule(['type' => 'pickup', 'address' => ['address' => 'Rua Longe, 1', 'city' => 'Jundiaí', 'state' => 'SP']])->assertUnprocessable();
        $this->assertSame(1, Appointment::count());
    }

    public function test_status_flow_records_times_and_feeds_the_day_list_and_kpis(): void
    {
        $first = $this->transportId('01310-100');
        $second = $this->transportId('01311-000');
        $third = $this->transportId('01312-000');

        $this->changeStatus($first, ['status' => 'on_the_way', 'driver_id' => $this->groomer->id])->assertOk()->assertJsonPath('data.driver.name', $this->groomer->name);
        $this->travel(20)->minutes();
        $this->changeStatus($first, ['status' => 'picked_up'])->assertOk();
        $this->travel(40)->minutes();
        $this->changeStatus($first, ['status' => 'delivered'])->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->changeStatus($first, ['status' => 'pending'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->changeStatus($first, ['status' => 'on_the_way', 'driver_id' => User::factory()->professional()->create()->id])->assertUnprocessable()->assertJsonValidationErrors('driver_id');

        $this->changeStatus($second, ['status' => 'on_the_way'])->assertOk();
        $this->travel(10)->minutes();
        $this->changeStatus($second, ['status' => 'picked_up'])->assertOk();
        $this->travel(20)->minutes();
        $this->changeStatus($second, ['status' => 'delivered'])->assertOk();
        $this->changeStatus($third, ['status' => 'on_the_way'])->assertOk();
        $this->changeStatus($third, ['status' => 'failed'])->assertOk();

        $day = $this->owner()->getJson('/api/professional/transports?date=2026-11-10')->assertOk()->assertJsonCount(3, 'data');
        $row = collect($day->json('data'))->firstWhere('id', $first);
        $this->assertSame([$this->pet->name, $this->tutor->name, $this->groomer->id, 'Centro'], [$row['pet']['name'], $row['client']['name'], $row['driver_id'], $row['zone']['name']]);
        $this->assertNotNull($row['picked_up_at']);
        $this->owner()->getJson('/api/professional/transports?date=2026-11-11')->assertOk()->assertJsonCount(0, 'data');

        // 2 entregues ÷ (2 + 1 falha); coleta (20 + 10) / 2 = 15 min; entrega (40 + 20) / 2 = 30 min.
        $this->owner()->getJson('/api/professional/transports/kpis?from=2026-11-01&to=2026-11-30')->assertOk()
            ->assertJsonPath('data.success_rate', '0.6667')->assertJsonPath('data.avg_pickup_minutes', '15.0')->assertJsonPath('data.avg_delivery_minutes', '30.0');
    }

    public function test_cancelling_drops_the_fee_line_and_other_organizations_are_blocked(): void
    {
        $id = $this->transportId('01310-100');
        $zones = "/api/organizations/{$this->clinic->id}/delivery-zones";
        $centro = DeliveryZone::where('name', 'Centro')->sole();
        $unused = DeliveryZone::where('name', 'Até 10 km')->sole();
        // Zona usada em transporte é desativada (o transporte guarda a zona); a não usada é apagada.
        $this->owner()->deleteJson("{$zones}/{$centro->id}")->assertOk()->assertJsonPath('data.active', false);
        $this->owner()->deleteJson("{$zones}/{$unused->id}")->assertOk();
        $this->assertNull(DeliveryZone::find($unused->id));
        $this->assertSame('Centro', $this->owner()->getJson('/api/professional/transports?date=2026-11-10')->json('data.0.zone.name'));
        $transport = PetTransport::findOrFail($id);
        $this->changeStatus($id, ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, $transport->appointment->services()->count());
        $this->assertEquals(0.0, (float) $transport->appointment->fresh()->price);

        $other = Organization::factory()->create();
        $stranger = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($other, 'organization')->create(['user_id' => $stranger->id]);
        $this->actingAs($stranger, 'sanctum')->getJson('/api/professional/transports?date=2026-11-10')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/professional/transports/{$id}/status", ['status' => 'on_the_way'])->assertNotFound();
        // As zonas são da organização: a outra não tem nenhuma.
        $this->postJson('/api/professional/transports/quote', ['zip_code' => '01310-100'])->assertUnprocessable();
        $this->getJson('/api/professional/transports/kpis?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonPath('data.success_rate', null);
        // Zonas da outra organização: invisíveis e intocáveis.
        $this->getJson($zones)->assertNotFound();
        $this->putJson("{$zones}/{$centro->id}", ['fee' => '1'])->assertNotFound();
        // Recepção vê a agenda (lê as zonas), mas não gerencia setores (não escreve zona).
        $this->actingAs($this->receptionist, 'sanctum')->getJson($zones)->assertOk();
        // Sem permissão da agenda (tutor): 403.
        $this->actingAs($this->tutor, 'sanctum')->getJson('/api/professional/transports')->assertForbidden();
    }

    /** @param  array<string, mixed>  $rule */
    private function zone(string $name, array $rule): void
    {
        $this->owner()->postJson("/api/organizations/{$this->clinic->id}/delivery-zones", ['name' => $name] + $rule)->assertCreated();
    }

    /** @param  array<string, mixed>  $payload */
    private function quote(array $payload): TestResponse
    {
        return $this->owner()->postJson('/api/professional/transports/quote', $payload);
    }

    /** @param  array<string, mixed>  $transport */
    private function schedule(array $transport): TestResponse
    {
        return $this->owner()->postJson('/api/professional/appointments', [
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => '2026-11-10',
            'appointment_time' => '10:00',
            'type' => 'grooming',
            'transport' => $transport,
        ]);
    }

    private function transportId(string $cep): int
    {
        $this->schedule(['type' => 'pickup', 'address' => ['zip_code' => $cep, 'address' => 'Rua A, 1', 'city' => 'São Paulo', 'state' => 'SP']])->assertCreated();

        return PetTransport::latest('id')->value('id');
    }

    /** @param  array<string, mixed>  $payload */
    private function changeStatus(int $id, array $payload): TestResponse
    {
        return $this->owner()->postJson("/api/professional/transports/{$id}/status", $payload);
    }

    private function owner(): self
    {
        return $this->actingAs($this->owner, 'sanctum');
    }
}
