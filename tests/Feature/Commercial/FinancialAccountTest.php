<?php

namespace Tests\Feature\Commercial;

use App\Models\FinancialAccount;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Contas bancárias/caixa/operadora — critérios de aceite do docs/gap-simplesvet/04.
 *
 * `PurchasePaymentSection.vue` chama `GET professional/financial-accounts` (fonte:
 * `2pets-app/src/services/financial-accounts.js`); antes desta rota, a chamada dava 404 e o
 * select de conta na tela de Compras ficava vazio.
 */
class FinancialAccountTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_index_returns_the_account_with_balance_without_n_plus_one(): void
    {
        $account = $this->makeAccount(['opening_balance' => 100]);
        $this->makeAccount(['name' => 'Caixa loja', 'type' => 'cash']);

        DB::enableQueryLog();

        $response = $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/financial-accounts')
            ->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(6, $queryCount, 'A listagem de contas não pode escalar 1 query por conta.');
        $response->assertJsonFragment(['id' => $account->id, 'balance' => 100.0]);
    }

    public function test_a_purchase_payment_section_style_call_gets_a_populated_select(): void
    {
        $this->makeAccount();

        $data = $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/professional/financial-accounts')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data, 'A tela de Compras depende deste select vir preenchido.');
        $this->assertArrayHasKey('name', $data[0]);
    }

    public function test_any_active_member_can_list_but_only_the_owner_creates_an_account(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/financial-accounts', ['name' => 'Banco X', 'type' => 'checking'])
            ->assertForbidden();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/financial-accounts', ['name' => 'Banco X', 'type' => 'checking'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Banco X');
    }

    public function test_only_the_owner_updates_an_account(): void
    {
        $account = $this->makeAccount();

        $this->actingAs($this->receptionist, 'sanctum')
            ->putJson("/api/professional/financial-accounts/{$account->id}", ['name' => 'Renomeada'])
            ->assertForbidden();

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/financial-accounts/{$account->id}", ['name' => 'Renomeada'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renomeada');
    }

    public function test_an_account_from_another_clinic_is_invisible(): void
    {
        $otherClinic = Organization::factory()->petshop()->create();
        $foreignAccount = FinancialAccount::create([
            'organization_id' => $otherClinic->id,
            'professional_id' => $this->owner->id,
            'name' => 'Banco de outra clínica',
            'type' => 'checking',
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/professional/financial-accounts/{$foreignAccount->id}")
            ->assertNotFound();
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeAccount(array $overrides = []): FinancialAccount
    {
        return FinancialAccount::create($overrides + [
            'organization_id' => $this->clinic->id,
            'professional_id' => $this->owner->id,
            'name' => 'Banco do Brasil',
            'type' => 'checking',
            'opening_balance' => 0,
        ]);
    }
}
