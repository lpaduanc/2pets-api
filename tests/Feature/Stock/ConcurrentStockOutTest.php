<?php

namespace Tests\Feature\Stock;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Stock\Concerns\BuildsStockFixtures;
use Tests\TestCase;

/**
 * Critério de aceite do doc 07: "duas vendas concorrentes do mesmo produto não corrompem o
 * saldo". A corrida real é duas requisições com o MESMO saldo em memória; aqui ela é
 * reproduzida com duas instâncias carregadas antes de qualquer saída (estado obsoleto), e o
 * teste verifica que o `StockService` trava a linha (`FOR UPDATE`) e lê o saldo dela, não do
 * model que recebeu.
 */
class ConcurrentStockOutTest extends TestCase
{
    use BuildsStockFixtures;
    use RefreshDatabase;

    public function test_two_outs_from_stale_instances_do_not_lose_an_update(): void
    {
        $this->buildStockClinic();
        $product = $this->product();
        app(StockService::class)->in($product, StockMovementType::OPENING_BALANCE, 10);

        $requestA = Product::find($product->id);
        $requestB = Product::find($product->id);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(StockService::class)->out($requestA, StockMovementType::SALE_OUT, 2);
        $second = app(StockService::class)->out($requestB, StockMovementType::SALE_OUT, 3);

        $this->assertSame(5, $second[0]->balance_after);
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame(5, $requestB->stock_quantity, 'o model de quem chamou volta com o saldo novo');
        $this->assertNotEmpty(
            array_filter($queries, fn (string $sql) => str_contains($sql, 'from "products"') && str_contains($sql, 'for update')),
            'a saída precisa travar a linha do produto'
        );
        $this->assertLedgerMatches($product);
    }
}
