<?php

namespace Tests\Feature;

use App\Enums\VetAccessLevel;
use App\Models\Appointment;
use App\Models\AppointmentCharge;
use App\Models\Exam;
use App\Models\Hospitalization;
use App\Models\Invoice;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Internação no fluxo de faturamento — contrato
 * docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md.
 *
 * Escrito conforme a regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class HospitalizationBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $vet;

    private User $tutor;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vet = User::factory()->professional()->create();
        $this->tutor = User::factory()->tutor()->create();
        $this->pet = Pet::factory()->create(['user_id' => $this->tutor->id]);

        PetVetAccess::create([
            'pet_id' => $this->pet->id,
            'veterinarian_id' => $this->vet->id,
            'granted_by' => $this->tutor->id,
            'access_level' => VetAccessLevel::WRITE,
            'status' => PetVetAccess::STATUS_ACCEPTED,
            'is_active' => true,
            'granted_at' => now(),
            'responded_at' => now(),
        ]);

        Sanctum::actingAs($this->vet);
    }

    /** §1: a admissão pendura num `Appointment` já `in_progress`, nunca cria uma ficha órfã. */
    public function test_admission_creates_an_in_progress_appointment_linked_to_it(): void
    {
        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Gastroenterite grave',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.appointment.type', 'hospitalization')
            ->assertJsonPath('data.appointment.status', 'in_progress');

        $hospitalization = Hospitalization::firstOrFail();
        $this->assertNotNull($hospitalization->appointment_id);
        $this->assertSame('in_progress', Appointment::findOrFail($hospitalization->appointment_id)->status);
    }

    /** Mesmo portão do walk-in (§6) — tutor que ainda não é cliente deste profissional. */
    public function test_admission_is_forbidden_when_the_tutor_is_not_yet_a_client(): void
    {
        $stranger = User::factory()->professional()->create();
        Sanctum::actingAs($stranger);

        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Observação',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Hospitalization::count());
    }

    /** Invariante 11 do doc 09: serviço anexado na admissão já lança fatura `pending`. */
    public function test_admission_with_an_initial_service_creates_a_pending_invoice(): void
    {
        $daily = $this->createDailyRateService();

        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Pancreatite',
            'services' => [['service_id' => $daily->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201);
        $appointmentId = $response->json('data.appointment_id');

        $this->assertDatabaseHas('invoices', [
            'appointment_id' => $appointmentId,
            'status' => 'pending',
            'total' => number_format($daily->price, 2, '.', ''),
        ]);
    }

    /** §2.1: sete dias internado geram SETE linhas — nunca uma linha com `quantity = 7`. */
    public function test_seven_days_of_daily_charges_create_seven_separate_lines(): void
    {
        $daily = $this->createDailyRateService();
        $hospitalization = $this->admit();

        for ($day = 0; $day < 7; $day++) {
            $this->postJson("/api/professional/appointments/{$hospitalization->appointment_id}/charges", [
                'service_id' => $daily->id,
                'reference_date' => now()->addDays($day)->toDateString(),
            ])->assertStatus(201);
        }

        $this->assertSame(
            7,
            AppointmentCharge::where('appointment_id', $hospitalization->appointment_id)->count()
        );
        $this->assertDatabaseHas('invoices', [
            'appointment_id' => $hospitalization->appointment_id,
            'total' => number_format($daily->price * 7, 2, '.', ''),
        ]);
    }

    /** §2.1: lançamento retroativo grava a data que a diária cobre, não `created_at`. */
    public function test_a_retroactively_launched_daily_charge_keeps_its_own_reference_date(): void
    {
        $daily = $this->createDailyRateService();
        $hospitalization = $this->admit();
        $twoDaysAgo = now()->subDays(2)->toDateString();

        $response = $this->postJson("/api/professional/appointments/{$hospitalization->appointment_id}/charges", [
            'service_id' => $daily->id,
            'reference_date' => $twoDaysAgo,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.reference_date', $twoDaysAgo);
    }

    /** §2.1: aviso derivado, nunca bloqueante — não força nem infere a diária faltante. */
    public function test_missing_daily_charges_count_reflects_ungapped_days_without_blocking_anything(): void
    {
        $daily = $this->createDailyRateService();
        $hospitalization = $this->admit(now()->subDays(2)->toDateString());

        // Só duas diárias lançadas para uma estadia de 3 dias corridos (hoje incluso).
        foreach ([now()->subDays(2), now()->subDay()] as $date) {
            $this->postJson("/api/professional/appointments/{$hospitalization->appointment_id}/charges", [
                'service_id' => $daily->id,
                'reference_date' => $date->toDateString(),
            ])->assertStatus(201);
        }

        $this->getJson("/api/professional/hospitalizations/{$hospitalization->id}")
            ->assertOk()
            ->assertJsonPath('data.missing_daily_charges_count', 1);
    }

    /**
     * Bug ao vivo (2026-09-16): a diária lançada como item LIVRE (sem `service_id` de
     * catálogo — o caso comum, quando o profissional só digita "Diária de internação") não
     * era contada antes desta correção, porque a contagem exigia `service.category =
     * hospitalization`. Resultado: 8 diárias lançadas, aviso dizendo que faltavam 8 —
     * indução a lançar de novo o que já estava lançado, ou seja, cobrança em duplicidade.
     */
    public function test_a_free_text_daily_charge_without_a_catalog_service_counts_towards_coverage(): void
    {
        $hospitalization = $this->admit(now()->subDays(2)->toDateString());

        foreach ([now()->subDays(2), now()->subDay(), now()] as $date) {
            $this->postJson("/api/professional/appointments/{$hospitalization->appointment_id}/charges", [
                'description' => 'Diária de internação',
                'unit_price' => 200,
                'reference_date' => $date->toDateString(),
            ])->assertStatus(201);
        }

        $this->getJson("/api/professional/hospitalizations/{$hospitalization->id}")
            ->assertOk()
            ->assertJsonPath('data.missing_daily_charges_count', 0);
    }

    /**
     * Duas diárias lançadas por engano no mesmo dia não podem produzir contagem negativa
     * nem esconder um dia realmente descoberto em outro ponto da estadia — o dia duplicado
     * conta uma vez só, e o dia sem nenhuma diária continua aparecendo como faltante.
     */
    public function test_a_duplicated_daily_charge_on_the_same_day_neither_goes_negative_nor_hides_a_missing_day(): void
    {
        $daily = $this->createDailyRateService();
        // Estadia de 3 dias corridos (hoje incluso): ontem, anteontem e hoje.
        $hospitalization = $this->admit(now()->subDays(2)->toDateString());

        // Duas linhas para o MESMO dia (anteontem) — lançamento em duplicidade.
        foreach ([now()->subDays(2), now()->subDays(2)] as $date) {
            $this->postJson("/api/professional/appointments/{$hospitalization->appointment_id}/charges", [
                'service_id' => $daily->id,
                'reference_date' => $date->toDateString(),
            ])->assertStatus(201);
        }

        // Ontem e hoje continuam sem diária nenhuma.
        $this->getJson("/api/professional/hospitalizations/{$hospitalization->id}")
            ->assertOk()
            ->assertJsonPath('data.missing_daily_charges_count', 2);
    }

    /**
     * Cobrança sem `reference_date` (legado, ou item que nunca teve data própria — ex.:
     * exame durante a internação) não descreve nenhum dia específico: precisa ser
     * ignorada pela contagem, nunca estourar a query nem ser tratada como diária.
     */
    public function test_a_charge_without_a_reference_date_is_ignored_by_the_coverage_count(): void
    {
        $daily = $this->createDailyRateService();
        $hospitalization = $this->admit(now()->toDateString());

        $this->postJson("/api/professional/appointments/{$hospitalization->appointment_id}/charges", [
            'service_id' => $daily->id,
            'reference_date' => now()->toDateString(),
        ])->assertStatus(201);

        // Item sem `reference_date` (ex.: exame avulso lançado na mesma conta).
        $this->postJson("/api/professional/appointments/{$hospitalization->appointment_id}/charges", [
            'description' => 'Raio-X tórax — durante internação',
            'unit_price' => 180,
        ])->assertStatus(201);

        $this->getJson("/api/professional/hospitalizations/{$hospitalization->id}")
            ->assertOk()
            ->assertJsonPath('data.missing_daily_charges_count', 0);
    }

    /**
     * Diária datada fora da janela da estadia (admissão–alta) não pode contar a favor de
     * nenhum dia DENTRO da janela — senão um lançamento errado (ou de outra internação por
     * engano) mascara um dia de fato descoberto.
     */
    public function test_a_daily_charge_dated_outside_the_stay_window_does_not_cover_any_day_inside_it(): void
    {
        $daily = $this->createDailyRateService();
        // Estadia de UM único dia: hoje.
        $hospitalization = $this->admit(now()->toDateString());

        // Diária datada de amanhã, lançada AINDA com a conta aberta (`in_progress`) —
        // fora da janela admissão–alta que será fechada em seguida (hoje–hoje).
        $this->postJson("/api/professional/appointments/{$hospitalization->appointment_id}/charges", [
            'service_id' => $daily->id,
            'reference_date' => now()->addDay()->toDateString(),
        ])->assertStatus(201);

        $this->putJson("/api/professional/hospitalizations/{$hospitalization->id}", [
            'status' => 'discharged',
            'discharge_date' => now()->toDateString(),
            'discharge_summary' => 'Alta sem intercorrências.',
        ])->assertOk();

        // Hoje continua sem diária nenhuma lançada.
        $this->getJson("/api/professional/hospitalizations/{$hospitalization->id}")
            ->assertOk()
            ->assertJsonPath('data.missing_daily_charges_count', 1);
    }

    /**
     * §2.2: um exame durante internação ativa entra na MESMA conta — nunca abre um
     * segundo `Appointment`/`Invoice`.
     */
    public function test_exam_during_active_hospitalization_bills_into_the_same_invoice(): void
    {
        $xray = Service::create([
            'professional_id' => $this->vet->id,
            'name' => 'Raio-X tórax',
            'category' => 'imaging',
            'duration' => 20,
            'price' => 180,
            'active' => true,
        ]);
        $hospitalization = $this->admit();

        $response = $this->postJson('/api/exams', [
            'pet_id' => $this->pet->id,
            'exam_type' => 'imaging',
            'exam_name' => 'Raio-X tórax',
            'exam_date' => now()->toDateString(),
            'service_id' => $xray->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.appointment_id', $hospitalization->appointment_id);

        $this->assertSame(
            1,
            Invoice::where('appointment_id', $hospitalization->appointment_id)->count()
        );
        $this->assertDatabaseHas('appointment_charges', [
            'appointment_id' => $hospitalization->appointment_id,
            'description' => 'Raio-X tórax — durante internação',
        ]);
    }

    /** Sem pet internado, `POST /exams` continua exatamente como antes (sem cobrança). */
    public function test_exam_outside_a_hospitalization_stay_is_unaffected(): void
    {
        $response = $this->postJson('/api/exams', [
            'pet_id' => $this->pet->id,
            'exam_type' => 'laboratory',
            'exam_name' => 'Hemograma',
            'exam_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
        $this->assertSame(0, AppointmentCharge::count());
        $this->assertSame(0, Invoice::count());
    }

    /** §2.2: sem `service_id` nem `unit_price`, não dá para lançar a cobrança do exame. */
    public function test_exam_during_hospitalization_requires_pricing_information(): void
    {
        $this->admit();

        $response = $this->postJson('/api/exams', [
            'pet_id' => $this->pet->id,
            'exam_type' => 'laboratory',
            'exam_name' => 'Hemograma',
            'exam_date' => now()->toDateString(),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Exam::count());
    }

    /**
     * §3: alta, transferência e óbito fecham a estadia igualmente — as três transicionam
     * o `Appointment` para `completed` pelo mesmo `closeWithoutFinalizing()`.
     *
     * @dataProvider stayClosingStatusProvider
     */
    public function test_every_stay_closing_status_completes_the_appointment(string $status): void
    {
        $hospitalization = $this->admit();

        $response = $this->putJson("/api/professional/hospitalizations/{$hospitalization->id}", [
            'status' => $status,
            'discharge_date' => now()->toDateString(),
            'discharge_summary' => 'Resumo de alta de teste.',
        ]);

        $response->assertOk()->assertJsonPath('data.status', $status);
        $this->assertSame('completed', Appointment::findOrFail($hospitalization->appointment_id)->status);
    }

    /** @return array<string, array{0: string}> */
    public static function stayClosingStatusProvider(): array
    {
        return [
            'discharged' => ['discharged'],
            'transferred' => ['transferred'],
            'deceased' => ['deceased'],
        ];
    }

    /** §4: `total_cost` deixa de ser aceito — enviar o campo não altera nada. */
    public function test_total_cost_sent_in_the_payload_is_silently_ignored(): void
    {
        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Observação',
            'total_cost' => 999999,
        ]);

        $response->assertStatus(201);
        $this->assertArrayNotHasKey('total_cost', $response->json('data'));
        $this->assertNull(Hospitalization::firstOrFail()->total_cost);
    }

    /**
     * §4/§6: desconto aplicado via `PUT /invoices/{id}` sobrevive a uma diária lançada
     * DEPOIS — antes desta correção, `AppointmentInvoiceService::syncItems()` recalculava
     * sempre com `discount = 0.0` fixo, descartando o desconto em silêncio.
     */
    public function test_a_discount_applied_before_survives_a_charge_launched_after(): void
    {
        $daily = $this->createDailyRateService();
        $hospitalization = $this->admit();

        $this->postJson("/api/professional/appointments/{$hospitalization->appointment_id}/charges", [
            'service_id' => $daily->id,
        ])->assertStatus(201);

        $invoice = Invoice::where('appointment_id', $hospitalization->appointment_id)->firstOrFail();
        $this->putJson("/api/professional/invoices/{$invoice->id}", ['discount' => 50])
            ->assertOk()
            ->assertJsonPath('data.discount', 50);

        $this->postJson("/api/professional/appointments/{$hospitalization->appointment_id}/charges", [
            'service_id' => $daily->id,
        ])->assertStatus(201);

        $expectedTotal = number_format(($daily->price * 2) - 50, 2, '.', '');
        $this->assertSame($expectedTotal, $invoice->fresh()->total);
    }

    /**
     * Achado do §0/§6: editar `services[]` por `PUT /appointments/{id}` depois que a
     * fatura já existe não pode deixá-la desatualizada em silêncio — passa a ser
     * recusado, direcionando para `/charges`.
     */
    public function test_editing_services_after_the_invoice_exists_is_rejected(): void
    {
        $service = Service::create([
            'professional_id' => $this->vet->id,
            'name' => 'Consulta',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 150,
            'active' => true,
        ]);

        $appointment = Appointment::create([
            'professional_id' => $this->vet->id,
            'client_id' => $this->tutor->id,
            'pet_id' => $this->pet->id,
            'appointment_date' => now()->addDay()->startOfDay(),
            'appointment_time' => '10:00:00',
            'duration' => 30,
            'type' => 'consultation',
            'status' => 'confirmed',
        ]);
        $appointment->services()->create(['service_id' => $service->id, 'quantity' => 1, 'unit_price' => 150]);
        $this->postJson("/api/professional/appointments/{$appointment->id}/start")->assertOk();

        $response = $this->putJson("/api/professional/appointments/{$appointment->id}", [
            'services' => [['service_id' => $service->id, 'quantity' => 2, 'unit_price' => 150]],
        ]);

        $response->assertStatus(422);
    }

    /** Hardening de corrida (§6): o índice único trava uma segunda `Invoice` no mesmo `appointment_id`. */
    public function test_invoices_appointment_id_has_a_unique_index(): void
    {
        $hospitalization = $this->admit();

        $duplicate = fn (): array => [
            'professional_id' => $this->vet->id,
            'client_id' => $this->tutor->id,
            'appointment_id' => $hospitalization->appointment_id,
            'invoice_number' => 'INV-'.uniqid(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'items' => json_encode([]),
            'subtotal' => 0,
            'total' => 0,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // A admissão já criou UMA invoice? Não — sem serviço anexado, nenhuma fatura nasce
        // ainda (invariante 11); esta é a primeira. A segunda precisa falhar.
        DB::table('invoices')->insert($duplicate());

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('invoices')->insert($duplicate());
    }

    private function admit(?string $admissionDate = null): Hospitalization
    {
        $response = $this->postJson('/api/professional/hospitalizations', [
            'pet_id' => $this->pet->id,
            'admission_date' => $admissionDate ?? now()->toDateString(),
            'reason' => 'Internação de teste',
        ]);

        $response->assertStatus(201);

        return Hospitalization::findOrFail($response->json('data.id'));
    }

    private function createDailyRateService(): Service
    {
        return Service::create([
            'professional_id' => $this->vet->id,
            'name' => 'Diária de internação',
            'category' => 'hospitalization',
            'duration' => 1440,
            'price' => 200,
            'active' => true,
        ]);
    }
}
