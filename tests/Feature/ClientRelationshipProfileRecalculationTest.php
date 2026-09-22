<?php

namespace Tests\Feature;

use App\Enums\AbcClass;
use App\Enums\ClientLifecycleStage;
use App\Models\ClientRelationshipProfile;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\Crm\ClientRelationshipProfileRecalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrato `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`. Escrito conforme a
 * regra do projeto: teste escrito, NÃO executado via `artisan test`.
 */
class ClientRelationshipProfileRecalculationTest extends TestCase
{
    use RefreshDatabase;

    private ClientRelationshipProfileRecalculator $recalculator;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00'));
        $this->recalculator = app(ClientRelationshipProfileRecalculator::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Regra de negócio 1 da spec: o mesmo tutor pode ser "A ativo" numa clínica e "abandonou"
     * em outra — cada `client_relationship_profiles` é isolado pelo escopo comercial.
     */
    public function test_same_client_has_independent_profiles_across_two_organizations(): void
    {
        $ownerA = $this->createOrganizationOwner();
        $ownerB = $this->createOrganizationOwner();
        $client = User::factory()->tutor()->create();

        $this->createPaidInvoice($ownerA, $client, now()->subDay());
        $this->createPaidInvoice($ownerB, $client, now()->subYears(4));

        $this->recalculator->recalculateForProfessional($ownerA);
        $this->recalculator->recalculateForProfessional($ownerB);

        $profileA = ClientRelationshipProfile::where('professional_id', $ownerA->id)->where('client_id', $client->id)->firstOrFail();
        $profileB = ClientRelationshipProfile::where('professional_id', $ownerB->id)->where('client_id', $client->id)->firstOrFail();

        $this->assertSame(ClientLifecycleStage::RETURNED_RECENTLY, $profileA->lifecycle_stage);
        $this->assertSame(ClientLifecycleStage::CHURNED_3_5Y, $profileB->lifecycle_stage);
        $this->assertNotEquals($profileA->organization_id, $profileB->organization_id);
    }

    /**
     * Determinismo: mesmo dado de entrada produz sempre o mesmo resultado, incluindo o
     * desempate por `client_id` na fronteira do percentil (regra de negócio 3 da spec).
     */
    public function test_abc_classification_is_deterministic_with_stable_tie_break(): void
    {
        $owner = $this->createOrganizationOwner();
        $clients = collect(range(1, 10))->map(function (int $i) use ($owner) {
            $client = User::factory()->tutor()->create();
            $this->createPaidInvoice($owner, $client, now()->subDay(), total: 100.0);

            return $client;
        });

        $this->recalculator->recalculateForProfessional($owner);
        $firstRun = ClientRelationshipProfile::where('professional_id', $owner->id)
            ->orderBy('client_id')
            ->pluck('abc_class', 'client_id');

        $this->recalculator->recalculateForProfessional($owner);
        $secondRun = ClientRelationshipProfile::where('professional_id', $owner->id)
            ->orderBy('client_id')
            ->pluck('abc_class', 'client_id');

        $this->assertEquals($firstRun, $secondRun);

        // 10 clientes com o MESMO gasto: top 15% = 1 cliente (A), próximos 35% = 4 (B, mas
        // arredondamento de posição/total pode variar em 1 — o importante é a fronteira ser
        // sempre a mesma nas duas rodadas, já garantido pelo assertEquals acima).
        $this->assertSame(AbcClass::A, $firstRun->first());
    }

    private function createOrganizationOwner(): User
    {
        $owner = User::factory()->professional()->create();
        $organization = Organization::factory()->create();

        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);

        return $owner;
    }

    private function createPaidInvoice(User $professional, User $client, Carbon $paymentDate, float $total = 150.0): Invoice
    {
        return Invoice::create([
            'professional_id' => $professional->id,
            'organization_id' => $professional->activeOrganizationId(),
            'client_id' => $client->id,
            'invoice_number' => 'INV-'.uniqid(),
            'issue_date' => $paymentDate->toDateString(),
            'due_date' => $paymentDate->toDateString(),
            'items' => [],
            'subtotal' => $total,
            'total' => $total,
            'status' => 'paid',
            'payment_date' => $paymentDate->toDateString(),
        ]);
    }
}
