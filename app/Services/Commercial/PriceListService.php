<?php

namespace App\Services\Commercial;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A "lista de preços" do doc 08 é a tabela que o balcão consulta e imprime — não um relatório
 * de catálogo. Por isso ela é enxuta (nome, código, estoque, preço) e filtrada por
 * `show_in_price_list`: o que a clínica não quer que o cliente veja no balcão fica de fora,
 * mesmo estando ativo à venda.
 *
 * Produto e serviço entram na MESMA lista, achatados na mesma forma. Quem consulta preço no
 * balcão não pensa em "tabela de produtos" e "tabela de serviços", pensa em "quanto custa".
 */
final class PriceListService
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forCounter(User $user, ?string $search = null, ?int $groupId = null): Collection
    {
        $products = $this->scope->scopeQuery(Product::query(), $user)
            ->active()
            ->where('show_in_price_list', true)
            ->when($search, fn ($q) => $q->where(function ($inner) use ($search): void {
                $inner->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%");
            }))
            ->when($groupId, fn ($q) => $q->where('product_group_id', $groupId))
            ->with('group:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product): array => [
                'type' => 'product',
                'id' => $product->id,
                'name' => $product->name,
                'code' => $product->code ?? $product->sku,
                'group' => $product->group?->name,
                'unit' => $product->unit_of_sale,
                'stock' => $product->controls_stock ? $product->stock_quantity : null,
                'price' => (float) $product->price,
            ]);

        $services = $this->scope->scopeQuery(Service::query(), $user)
            ->active()
            ->where('show_in_price_list', true)
            ->when($search, fn ($q) => $q->where(function ($inner) use ($search): void {
                $inner->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%");
            }))
            ->when($groupId, fn ($q) => $q->where('product_group_id', $groupId))
            ->with('group:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Service $service): array => [
                'type' => 'service',
                'id' => $service->id,
                'name' => $service->name,
                'code' => $service->code,
                'group' => $service->group?->name,
                'unit' => 'SV',
                'stock' => null,
                'price' => (float) $service->price,
            ]);

        return $products->concat($services)->sortBy('name')->values();
    }

    /**
     * CSV de uma coluna por campo visível na tela — é o formato que a recepção abre no Excel
     * e imprime. O PDF do documento fica para a camada de apresentação (o front já imprime a
     * própria tela); gerar PDF no backend exigiria uma dependência nova só para reproduzir o
     * que o `window.print()` da `PriceListPage` já entrega.
     */
    public function toCsv(User $user, ?string $search = null, ?int $groupId = null): string
    {
        $rows = $this->forCounter($user, $search, $groupId);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Tipo', 'Nome', 'Código', 'Grupo', 'Unidade', 'Estoque', 'Preço'], ';');

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['type'] === 'product' ? 'Produto' : 'Serviço',
                $row['name'],
                $row['code'] ?? '',
                $row['group'] ?? '',
                $row['unit'],
                $row['stock'] ?? '',
                number_format($row['price'], 2, ',', ''),
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
