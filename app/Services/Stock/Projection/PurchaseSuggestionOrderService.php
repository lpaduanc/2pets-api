<?php

namespace App\Services\Stock\Projection;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Purchase\PurchaseOrderService;
use App\Support\Money\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Gerar pedido de compra" a partir das sugestões da projeção (PLANO 3.4).
 *
 * Um pedido em RASCUNHO por fornecedor, pelo `PurchaseOrderService` de sempre (código
 * sequencial, custo = último custo do produto, total em 2 casas). Fornecedor de cada item: o
 * informado no item, senão o padrão do produto (`preferred_supplier_id`), senão o da última
 * compra (`last_supplier_id`).
 *
 * Decisão: produto sem fornecedor resolvido → 422 e NENHUM pedido é criado. `purchase_orders`
 * exige fornecedor (NOT NULL; o pedido é o documento que vai para ele), e gerar metade dos
 * pedidos deixaria a tela sem saber o que já foi pedido. O erro aponta o item
 * (`items.N.supplier_id`) para a tela pedir o fornecedor ali mesmo e reenviar.
 */
final class PurchaseSuggestionOrderService
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly PurchaseProjectionService $projection,
        private readonly PurchaseOrderService $orders,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity?: string|int|float|null, supplier_id?: int|null}>  $items
     * @return Collection<int, PurchaseOrder>
     */
    public function generate(User $user, array $items, int $window): Collection
    {
        $products = $this->scope->scopeQuery(Product::query(), $user)
            ->whereIn('id', array_column($items, 'product_id'))
            ->with(['preferredSupplier', 'lastSupplier'])->get()->keyBy('id');
        $projected = $this->projection->project($products->filter(fn (Product $p): bool => $this->needsProjection($items, $p->id))->values(), $window);

        [$lines, $errors] = $this->resolve($user, $items, $products, $projected);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $notes = sprintf('Gerado pela projeção de compra (consumo dos últimos %d dias).', $window);

        return DB::transaction(fn (): Collection => collect($lines)->groupBy('supplier_id')
            ->map(fn (Collection $group, int $supplierId): PurchaseOrder => $this->orders->create($user, [
                'supplier_id' => $supplierId,
                'notes' => $notes,
                'items' => $group->map(fn (array $line): array => ['product_id' => $line['product_id'], 'quantity' => $line['quantity']])->values()->all(),
            ]))->values());
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  Collection<int, Product>  $products
     * @param  array<int, array<string, mixed>>  $projected
     * @return array{0: list<array{product_id: int, quantity: string, supplier_id: int}>, 1: array<string, string>}
     */
    private function resolve(User $user, array $items, Collection $products, array $projected): array
    {
        $lines = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $product = $products->get((int) $item['product_id']);

            if ($product === null) {
                $errors["items.{$index}.product_id"] = 'Produto não encontrado.';

                continue;
            }

            $quantity = Quantity::of($item['quantity'] ?? $projected[$product->id]['suggested_quantity'] ?? '0');
            $supplierId = $this->supplierFor($user, $item, $product);

            if (! $quantity->isPositive()) {
                $errors["items.{$index}.quantity"] = sprintf('"%s" não tem sugestão de compra: informe a quantidade.', $product->name);
            }

            if ($supplierId === null && ! empty($item['supplier_id'])) {
                $errors["items.{$index}.supplier_id"] = 'Fornecedor não encontrado.';
            } elseif ($supplierId === null) {
                $errors["items.{$index}.supplier_id"] = sprintf('"%s" não tem fornecedor padrão: informe o fornecedor.', $product->name);
            }

            if ($quantity->isPositive() && $supplierId !== null) {
                $lines[] = ['product_id' => $product->id, 'quantity' => $quantity->toString(), 'supplier_id' => $supplierId];
            }
        }

        return [$lines, $errors];
    }

    /** @param  array<string, mixed>  $item */
    private function supplierFor(User $user, array $item, Product $product): ?int
    {
        if (! empty($item['supplier_id'])) {
            return $this->scope->scopeQuery(Supplier::query(), $user)->whereKey((int) $item['supplier_id'])->value('id');
        }

        return $product->preferred_supplier_id ?? $product->last_supplier_id;
    }

    /** @param  list<array<string, mixed>>  $items */
    private function needsProjection(array $items, int $productId): bool
    {
        foreach ($items as $item) {
            if ((int) $item['product_id'] === $productId && ! isset($item['quantity'])) {
                return true;
            }
        }

        return false;
    }
}
