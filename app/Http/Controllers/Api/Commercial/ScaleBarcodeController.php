<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Resources\Commercial\SaleItemResource;
use App\Http\Resources\Commercial\SaleResource;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\SaleService;
use App\Services\Stock\Bulk\ScaleBarcodeParser;
use App\Services\Stock\Bulk\ScaleLineCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * `POST pdv/scale-barcode` (PLANO 3.3): o PDV lê a etiqueta da balança. Sem `sale_id`, só resolve
 * (produto, quantidade, preço, total); com `sale_id`, lança o item na venda com a quantidade
 * decimal e o total exato da etiqueta (modo preço: preço da linha ajustado ao centavo que
 * reproduz o total, ver `ScaleLineCalculator`) e devolve a venda atualizada.
 */
class ScaleBarcodeController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly ScaleBarcodeParser $parser,
        private readonly ScaleLineCalculator $lines,
        private readonly SaleService $sales,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['barcode' => ['required', 'string', 'max:20'], 'sale_id' => ['nullable', 'integer']]);
        $organizationId = $this->scope->primaryOrganizationId($request->user());
        $settings = $organizationId === null ? null : Organization::query()->whereKey($organizationId)->value('scale_settings');

        try {
            $parsed = $this->parser->parse(preg_replace('/\D/', '', $data['barcode']), is_string($settings) ? json_decode($settings, true) : $settings);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['barcode' => $e->getMessage()]);
        }

        $product = $this->scope->scopeQuery(Product::query(), $request->user())->where('is_active', true)->whereNotNull('scale_code')
            ->whereRaw('CAST(scale_code AS INTEGER) = ?', [(int) $parsed['scale_code']])->first();

        if ($product === null) {
            throw ValidationException::withMessages(['barcode' => sprintf('Nenhum produto com o código de balança %s.', $parsed['scale_code'])]);
        }

        $line = $this->lines->line($parsed['mode'], $parsed['value'], (string) ($product->price ?? '0'));

        if (! $product->acceptsQuantity($line['quantity'])) {
            throw ValidationException::withMessages(['barcode' => sprintf('"%s" não aceita quantidade fracionada: ajuste a unidade (kg) do cadastro.', $product->name)]);
        }

        $resolved = ['product' => ['id' => $product->id, 'name' => $product->name, 'scale_code' => $product->scale_code, 'price' => (string) $product->price], 'mode' => $parsed['mode']] + $line;

        if (empty($data['sale_id'])) {
            return response()->json(['data' => $resolved]);
        }

        $sale = $this->scope->scopeQuery(Sale::query(), $request->user())->findOrFail((int) $data['sale_id']);
        Gate::forUser($request->user())->authorize('update', $sale);
        $item = $this->sales->addItem($sale, $request->user(), [
            'sellable_type' => 'product', 'sellable_id' => $product->id, 'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'], 'discount' => $line['discount'], '_scale_label' => true,
        ]);

        return response()->json(['data' => $resolved, 'item' => new SaleItemResource($item), 'sale' => new SaleResource($sale->fresh(Sale::RESOURCE_RELATIONS))], 201);
    }
}
