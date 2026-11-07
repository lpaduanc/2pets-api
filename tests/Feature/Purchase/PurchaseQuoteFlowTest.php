<?php

namespace Tests\Feature\Purchase;

use App\Mail\PurchaseQuoteRequestMail;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Cotação com fornecedores (docs/gap-petflow/PLANO.md, 3.5): fluxo completo (criar, enviar,
 * fornecedor vê e responde, comparativo com frete rateado, vencedor por item, pedidos gerados),
 * recusa, token vencido ou inválido, resposta depois de fechada, outra organização, permissão e
 * rate limit. Hoje congelado em 07/11/2026, meio da tarde em São Paulo.
 */
class PurchaseQuoteFlowTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private Supplier $alpha;

    private Supplier $beta;

    private Supplier $noEmail;

    private Product $food;

    private Product $sand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-07 18:00:00', 'UTC'));
        Mail::fake();
        $this->buildClinic();
        $this->alpha = $this->supplier('Alpha Distribuidora', 'vendas@alpha.test');
        $this->beta = $this->supplier('Beta Atacado', 'cotacao@beta.test');
        $this->noEmail = $this->supplier('Gama Sem E-mail', null);
        $this->food = $this->makeProduct(['name' => 'Ração Adulto 10kg', 'stock_quantity' => 0]);
        $this->sand = $this->makeProduct(['name' => 'Areia Sanitária', 'stock_quantity' => 0]);
    }

    public function test_full_flow_from_request_to_purchase_orders(): void
    {
        $created = $this->createQuote()->assertCreated()
            ->assertJsonPath('data.number', 1)->assertJsonPath('data.status', 'draft')->assertJsonPath('data.valid_until', '2026-11-14')
            ->assertJsonPath('data.suppliers.0.public_url', null);
        $id = $created->json('data.id');

        $sent = $this->owner()->postJson("/api/professional/purchase-quotes/{$id}/send")->assertOk()->assertJsonPath('data.status', 'sent');
        Mail::assertQueued(PurchaseQuoteRequestMail::class, 2);
        Mail::assertQueued(PurchaseQuoteRequestMail::class, fn (PurchaseQuoteRequestMail $mail): bool => $mail->hasTo('vendas@alpha.test') && str_starts_with($mail->responseUrl, rtrim(config('app.frontend_pro_url'), '/').'/cotacao/')
            && str_contains($mail->render(), $mail->responseUrl) && str_contains($mail->render(), 'Ração Adulto 10kg'));
        $suppliers = collect($sent->json('data.suppliers'))->keyBy('supplier_id');
        // Sem e-mail: nada na fila, mas o link existe para a clínica copiar.
        $this->assertSame([false, null], [$suppliers[$this->noEmail->id]['has_email'], $suppliers[$this->noEmail->id]['emailed_at']]);
        [$alpha, $beta, $gama] = array_map(fn (Supplier $s): string => $this->token($suppliers[$s->id]['public_url']), [$this->alpha, $this->beta, $this->noEmail]);

        // O fornecedor abre o link sem login: vê só nome da clínica e itens.
        $public = $this->getJson("/api/public/purchase-quotes/{$alpha}")->assertOk()
            ->assertJsonPath('data.status', 'viewed')->assertJsonPath('data.can_respond', true)->assertJsonPath('data.clinic.name', $this->clinic->business_name);
        $this->assertSame(['number', 'clinic', 'supplier', 'status', 'status_label', 'valid_until', 'notes', 'can_respond', 'items', 'response', 'responded_at', 'rejected_at'], array_keys($public->json('data')));
        $this->assertSame(['name'], array_keys($public->json('data.clinic')));
        $items = collect($public->json('data.items'))->pluck('id', 'product_name');

        $this->respond($alpha, ['freight' => '25.00', 'delivery_days' => 5, 'payment_terms' => '30 dias', 'items' => [
            ['quote_item_id' => $items[$this->food->name], 'unit_price' => '20.00'], ['quote_item_id' => $items[$this->sand->name], 'unit_price' => '60.00'],
        ]])->assertOk()->assertJsonPath('data.status', 'responded')->assertJsonPath('data.can_respond', false);
        $this->respond($beta, ['freight' => '0', 'delivery_days' => 3, 'items' => [
            ['quote_item_id' => $items[$this->food->name], 'unit_price' => '21.50'], ['quote_item_id' => $items[$this->sand->name], 'unit_price' => '62.00'],
        ]])->assertOk();

        // Já respondida: 410 `purchase_quote_answered` com a proposta para só leitura; sem segunda resposta.
        $this->getJson("/api/public/purchase-quotes/{$alpha}")->assertStatus(410)
            ->assertJsonPath('code', 'purchase_quote_answered')->assertJsonPath('data.response.freight', '25.00')->assertJsonPath('data.can_respond', false);
        $this->respond($alpha, ['items' => [['quote_item_id' => $items[$this->food->name], 'unit_price' => '1.00']]])->assertStatus(410)->assertJsonPath('code', 'purchase_quote_answered');

        // Reenvio sem corpo: só quem ainda não respondeu recebe (Gama não tem e-mail → ninguém).
        Mail::fake();
        $this->owner()->postJson("/api/professional/purchase-quotes/{$id}/send")->assertOk();
        Mail::assertNothingQueued();

        // Comparativo (golden cotacao-01): frete 25,00 rateado 10,00 / 15,00.
        $comparison = $this->owner()->getJson("/api/professional/purchase-quotes/{$id}/comparison")->assertOk()->json();
        $byItem = collect($comparison['items'])->keyBy('product_id');
        $this->assertSame([$this->alpha->id, $this->beta->id], [$byItem[$this->food->id]['lowest_supplier_id'], $byItem[$this->sand->id]['lowest_supplier_id']]);
        $foodOffers = collect($byItem[$this->food->id]['offers'])->keyBy('supplier_id');
        $this->assertSame(['20.0000', '21.0000', 5, true], [$foodOffers[$this->alpha->id]['unit_price'], $foodOffers[$this->alpha->id]['real_unit_cost'], $foodOffers[$this->alpha->id]['delivery_days'], $foodOffers[$this->alpha->id]['is_lowest']]);
        $this->assertSame(['63.0000', false], [collect($byItem[$this->sand->id]['offers'])->firstWhere('supplier_id', $this->alpha->id)['real_unit_cost'], collect($byItem[$this->sand->id]['offers'])->firstWhere('supplier_id', $this->alpha->id)['is_lowest']]);

        // Vencedor por item → um pedido por fornecedor, com o preço cotado; a cotação fecha.
        $orders = $this->owner()->postJson("/api/professional/purchase-quotes/{$id}/orders", ['winners' => [
            ['quote_item_id' => $items[$this->food->name], 'supplier_id' => $this->alpha->id],
            ['quote_item_id' => $items[$this->sand->name], 'supplier_id' => $this->beta->id],
        ]])->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('quote.status', 'closed');
        $bySupplier = collect($orders->json('data'))->keyBy('supplier_id');
        $this->assertSame(['draft', '2026-11-12', '20.0000', 200.0], [$bySupplier[$this->alpha->id]['status'], $bySupplier[$this->alpha->id]['expected_at'], $bySupplier[$this->alpha->id]['items'][0]['unit_cost'], (float) $bySupplier[$this->alpha->id]['total']]);
        $this->assertStringContainsString('Frete cotado: R$ 25,00', $bySupplier[$this->alpha->id]['notes']);
        $this->assertSame(['2026-11-10', 310.0], [$bySupplier[$this->beta->id]['expected_at'], (float) $bySupplier[$this->beta->id]['total']]);

        // Fechada: quem não respondeu não vê nem responde mais; pedido não sai duas vezes.
        $this->getJson("/api/public/purchase-quotes/{$gama}")->assertStatus(410)
            ->assertJsonPath('code', 'purchase_quote_expired')->assertJsonPath('message', 'Esta cotação foi encerrada pela clínica.');
        $this->respond($gama, ['items' => [['quote_item_id' => $items[$this->food->name], 'unit_price' => '1.00']]])->assertStatus(410)->assertJsonPath('code', 'purchase_quote_expired');
        $this->owner()->postJson("/api/professional/purchase-quotes/{$id}/orders", ['winners' => [['quote_item_id' => $items[$this->food->name], 'supplier_id' => $this->beta->id]]])->assertUnprocessable();
        $this->owner()->deleteJson("/api/professional/purchase-quotes/{$id}")->assertUnprocessable();
        $this->assertSame(2, PurchaseOrder::count());
    }

    public function test_rejection_expiry_invalid_token_and_invalid_winners(): void
    {
        $id = $this->createQuote([$this->alpha->id, $this->beta->id])->json('data.id');
        $suppliers = collect($this->owner()->postJson("/api/professional/purchase-quotes/{$id}/send")->json('data.suppliers'))->keyBy('supplier_id');
        [$alpha, $beta] = [$this->token($suppliers[$this->alpha->id]['public_url']), $this->token($suppliers[$this->beta->id]['public_url'])];
        $itemId = $this->owner()->getJson("/api/professional/purchase-quotes/{$id}")->json('data.items.0.id');

        // Editar depois de enviada: não.
        $this->owner()->patchJson("/api/professional/purchase-quotes/{$id}", ['notes' => 'x'])->assertUnprocessable();

        // Recusa: fica registrada e o link vira só leitura.
        $this->postJson("/api/public/purchase-quotes/{$alpha}/reject", ['reason' => 'Sem estoque'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->respond($alpha, ['items' => [['quote_item_id' => $itemId, 'unit_price' => '5']]])->assertStatus(410)
            ->assertJsonPath('code', 'purchase_quote_answered')->assertJsonPath('message', 'Esta cotação já foi recusada.');
        $this->getJson("/api/public/purchase-quotes/{$alpha}")->assertStatus(410)->assertJsonPath('data.status', 'rejected');

        // Reenvio para um fornecedor só (`quote_supplier_ids`); recusado não recebe; id de fora → 422.
        Mail::fake();
        $this->owner()->postJson("/api/professional/purchase-quotes/{$id}/send", ['quote_supplier_ids' => [$suppliers[$this->beta->id]['id']]])->assertOk();
        Mail::assertQueued(PurchaseQuoteRequestMail::class, fn (PurchaseQuoteRequestMail $mail): bool => $mail->hasTo('cotacao@beta.test'));
        Mail::assertQueuedCount(1);
        $this->owner()->postJson("/api/professional/purchase-quotes/{$id}/send", ['quote_supplier_ids' => [$suppliers[$this->alpha->id]['id']]])->assertOk();
        Mail::assertQueuedCount(1);
        $this->owner()->postJson("/api/professional/purchase-quotes/{$id}/send", ['quote_supplier_ids' => [999999]])->assertUnprocessable()->assertJsonValidationErrors('quote_supplier_ids');

        // Item de outra cotação não entra.
        $this->respond($beta, ['items' => [['quote_item_id' => 999999, 'unit_price' => '5']]])->assertUnprocessable()->assertJsonValidationErrors('items.0.quote_item_id');

        // Vencedor de quem não respondeu: 422 e nada é criado.
        $this->owner()->postJson("/api/professional/purchase-quotes/{$id}/orders", ['winners' => [['quote_item_id' => $itemId, 'supplier_id' => $this->alpha->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('winners.0');
        $this->assertSame(0, PurchaseOrder::count());

        // Token inválido: 404, sem dizer se a cotação existe.
        $this->getJson('/api/public/purchase-quotes/nao-existe')->assertNotFound();
        $this->getJson('/api/public/purchase-quotes/'.str_repeat('a', 48))->assertNotFound();

        // Prazo (14/11) passou sem resposta: 410 com a data; para a clínica, "expired".
        $this->travelTo(CarbonImmutable::parse('2026-11-15 13:00:00', 'UTC'));
        $this->getJson("/api/public/purchase-quotes/{$beta}")->assertStatus(410)
            ->assertJsonPath('code', 'purchase_quote_expired')->assertJsonPath('message', 'O prazo desta cotação venceu em 14/11/2026.');
        $this->respond($beta, ['items' => [['quote_item_id' => $itemId, 'unit_price' => '5']]])->assertStatus(410);
        $statuses = collect($this->owner()->getJson("/api/professional/purchase-quotes/{$id}")->json('data.suppliers'))->pluck('status', 'supplier_id');
        $this->assertSame(['rejected', 'expired'], [$statuses[$this->alpha->id], $statuses[$this->beta->id]]);
    }

    public function test_other_organization_and_permission(): void
    {
        $id = $this->createQuote()->json('data.id');
        $other = Organization::factory()->create();
        $stranger = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($other, 'organization')->create(['user_id' => $stranger->id]);
        $theirs = Supplier::create(['organization_id' => $other->id, 'professional_id' => $stranger->id, 'legal_name' => 'Deles']);

        $this->actingAs($stranger, 'sanctum')->getJson("/api/professional/purchase-quotes/{$id}")->assertNotFound();
        $this->getJson('/api/professional/purchase-quotes')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/professional/purchase-quotes/{$id}/send")->assertNotFound();
        $this->postJson('/api/professional/purchase-quotes', ['items' => [['product_id' => $this->food->id, 'quantity' => 1]], 'supplier_ids' => [$theirs->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $this->owner()->postJson('/api/professional/purchase-quotes', ['items' => [['product_id' => $this->food->id, 'quantity' => 1]], 'supplier_ids' => [$theirs->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_ids.0');

        // Sem `products.update` (aqui, um tutor): não cria nem envia.
        $this->actingAs($this->tutor, 'sanctum')->postJson('/api/professional/purchase-quotes', ['items' => [['product_id' => $this->food->id, 'quantity' => 1]], 'supplier_ids' => [$this->alpha->id]])->assertForbidden();
        $this->postJson("/api/professional/purchase-quotes/{$id}/send")->assertForbidden();
        Mail::assertNothingQueued();
    }

    public function test_public_routes_are_rate_limited(): void
    {
        foreach (range(1, 30) as $attempt) {
            $this->getJson('/api/public/purchase-quotes/'.str_repeat('b', 48))->assertNotFound();
        }

        $this->getJson('/api/public/purchase-quotes/'.str_repeat('b', 48))->assertStatus(429);

        foreach (range(1, 10) as $attempt) {
            $this->postJson('/api/public/purchase-quotes/'.str_repeat('c', 48).'/reject')->assertNotFound();
        }

        $this->postJson('/api/public/purchase-quotes/'.str_repeat('c', 48).'/reject')->assertStatus(429);
    }

    private function supplier(string $name, ?string $email): Supplier
    {
        return Supplier::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'legal_name' => $name, 'email' => $email]);
    }

    /** @param  list<int>|null  $supplierIds */
    private function createQuote(?array $supplierIds = null): TestResponse
    {
        return $this->owner()->postJson('/api/professional/purchase-quotes', [
            'items' => [['product_id' => $this->food->id, 'quantity' => 10], ['product_id' => $this->sand->id, 'quantity' => 5]],
            'supplier_ids' => $supplierIds ?? [$this->alpha->id, $this->beta->id, $this->noEmail->id],
        ]);
    }

    private function owner(): self
    {
        return $this->actingAs($this->owner, 'sanctum');
    }

    /** @param  array<string, mixed>  $payload */
    private function respond(string $token, array $payload): TestResponse
    {
        return $this->postJson("/api/public/purchase-quotes/{$token}/response", $payload);
    }

    private function token(string $url): string
    {
        return substr($url, strrpos($url, '/') + 1);
    }
}
