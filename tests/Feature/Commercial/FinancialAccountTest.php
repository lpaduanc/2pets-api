<?php

namespace Tests\Feature\Commercial;

use App\Enums\CashMovementType;
use App\Models\CashRegisterMovement;
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

    /**
     * N+1 se prova pela FORMA da contagem, não por um teto fixo: a mesma listagem com 2 e com 6
     * contas (todas com movimentos, para o eager load de `paymentMethod` entrar) tem de rodar o
     * MESMO número de queries. O teto absoluto de antes media junto o aquecimento do cache de
     * permissões do Spatie (4 queries no primeiro `permission:` do request), que nada tem a ver
     * com a listagem e muda conforme o estado do cache — o teste quebrava sem N+1 nenhum.
     */
    public function test_index_returns_the_account_with_balance_without_n_plus_one(): void
    {
        $registerId = $this->openRegisterFor($this->receptionist);
        $account = $this->makeAccountWithMovement($registerId, ['opening_balance' => 100]);
        $this->makeAccountWithMovement($registerId, ['name' => 'Caixa loja', 'type' => 'cash']);

        // Primeira chamada descartada: aquece o cache do Spatie e o usuário autenticado, que
        // só são lidos uma vez no processo de teste e distorceriam a primeira medição.
        $this->countIndexQueries();
        $withTwo = $this->countIndexQueries();

        for ($i = 0; $i < 4; $i++) {
            $this->makeAccountWithMovement($registerId, ['name' => "Conta extra {$i}"]);
        }

        $withSix = $this->countIndexQueries();

        $this->assertSame($withTwo, $withSix, "Listagem de contas escalou com o número de contas ({$withTwo} → {$withSix} queries): N+1.");
        $this->actingAs($this->receptionist, 'sanctum')->getJson('/api/professional/financial-accounts')
            ->assertOk()
            ->assertJsonFragment(['id' => $account->id, 'balance' => 110.0]);
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
    private function countIndexQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->receptionist, 'sanctum')->getJson('/api/professional/financial-accounts')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** Conta com um suprimento de R$ 10,00 lançado nela. @param  array<string, mixed>  $overrides */
    private function makeAccountWithMovement(int $registerId, array $overrides = []): FinancialAccount
    {
        $account = $this->makeAccount($overrides);
        CashRegisterMovement::create([
            'cash_register_id' => $registerId, 'type' => CashMovementType::SUPPLY->value, 'account_id' => $account->id,
            'amount' => 10, 'occurred_at' => now()->subMinute(), 'description' => 'Suprimento', 'user_id' => $this->receptionist->id,
        ]);

        return $account;
    }

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
