<?php

namespace App\Http\Controllers\Api\Stock;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PackageOpening;
use App\Models\Product;
use App\Models\ProductConversion;
use App\Models\User;
use App\Services\Commercial\Authorization\SalePermissionGate;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Stock\Bulk\PackageOpeningService;
use App\Services\Stock\Bulk\ScaleBarcodeParser;
use App\Services\Stock\Bulk\ToledoMgvExporter;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Granel e balança no estoque (PLANO 3.3): conversões saco → granel, abertura de embalagem,
 * configuração da etiqueta da balança e o arquivo de carga Toledo MGV. Leitura no escopo da
 * organização (como o resto do estoque); escrita com `products.update` pelo cargo na
 * organização (`SalePermissionGate`).
 */
class BulkStockController extends Controller
{
    private const MANAGE = 'products.update';

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SalePermissionGate $permissions,
        private readonly PackageOpeningService $openings,
    ) {}

    public function conversions(Request $request): JsonResponse
    {
        $items = $this->scoped(ProductConversion::query(), $request->user())->with(['fromProduct:id,name,unit_of_sale', 'toProduct:id,name,unit_of_sale'])->orderBy('id')->get();

        return response()->json(['data' => $items->map(fn (ProductConversion $c): array => $this->presentConversion($c))->values()]);
    }

    public function storeConversion(Request $request): JsonResponse
    {
        $this->authorizeManage($request->user());
        $data = $this->validatedConversion($request, null);
        $conversion = ProductConversion::create($data + $this->scope->ownershipFor($request->user()));

        return response()->json(['data' => $this->presentConversion($conversion->load(['fromProduct', 'toProduct'])), 'message' => 'Conversão cadastrada.'], 201);
    }

    public function updateConversion(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request->user());
        $conversion = $this->scoped(ProductConversion::query(), $request->user())->findOrFail($id);
        $conversion->update($this->validatedConversion($request, $conversion));

        return response()->json(['data' => $this->presentConversion($conversion->fresh(['fromProduct', 'toProduct'])), 'message' => 'Conversão atualizada.']);
    }

    public function destroyConversion(Request $request, int $id): JsonResponse
    {
        $this->authorizeManage($request->user());
        $this->scoped(ProductConversion::query(), $request->user())->findOrFail($id)->delete();

        return response()->json(['message' => 'Conversão removida.']);
    }

    public function openings(Request $request): JsonResponse
    {
        $items = $this->scoped(PackageOpening::query(), $request->user())->with(['fromProduct:id,name', 'toProduct:id,name'])->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $items->map(fn (PackageOpening $o): array => $this->presentOpening($o))->values()]);
    }

    public function open(Request $request): JsonResponse
    {
        $this->authorizeManage($request->user());
        $data = $request->validate([
            'from_product_id' => ['required', 'integer'],
            'to_product_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $opening = $this->openings->open($request->user(), (int) $data['from_product_id'], isset($data['to_product_id']) ? (int) $data['to_product_id'] : null, (string) $data['quantity'], $data['notes'] ?? null);

        return response()->json(['data' => $this->presentOpening($opening), 'message' => 'Embalagem aberta.'], 201);
    }

    public function scaleSettings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentScaleSettings($this->organizationFor($request->user()))]);
    }

    public function updateScaleSettings(Request $request): JsonResponse
    {
        $this->authorizeManage($request->user());
        $data = $request->validate([
            'prefixes' => ['required', 'array', 'min:1', 'max:5'],
            'prefixes.*' => ['required', 'string', 'regex:/^\d{1,2}$/', 'distinct'],
            'code_length' => ['required', 'integer', 'between:4,6'],
            'value_length' => ['required', 'integer', 'between:4,6'],
            'mode' => ['required', Rule::in(['weight', 'price'])],
        ]);

        if (max(array_map('strlen', $data['prefixes'])) + $data['code_length'] + $data['value_length'] > 12) {
            throw ValidationException::withMessages(['value_length' => 'Prefixo + código + valor não cabem nos 12 dígitos da etiqueta (o 13º é o verificador).']);
        }

        $organization = $this->organizationFor($request->user());
        $organization->update(['scale_settings' => $data]);

        return response()->json(['data' => $this->presentScaleSettings($organization->fresh()), 'message' => 'Configuração da balança atualizada.']);
    }

    public function export(Request $request, ToledoMgvExporter $exporter): Response
    {
        $request->validate(['format' => ['required', Rule::in(['toledo_mgv'])]]);
        $products = $this->scoped(Product::query(), $request->user())->where('is_active', true)->where('sold_by_weight', true)->whereNotNull('scale_code')->orderBy('scale_code')->get();

        return response($exporter->export($products), 200, [
            'Content-Type' => 'text/plain; charset=US-ASCII',
            'Content-Disposition' => 'attachment; filename="ITENSMGV.TXT"',
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedConversion(Request $request, ?ProductConversion $current): array
    {
        $required = $current === null ? 'required' : 'sometimes';
        $data = $request->validate([
            'from_product_id' => [$required, 'integer'],
            'to_product_id' => [$required, 'integer', 'different:from_product_id'],
            'factor' => [$required, 'numeric', 'gt:0', 'max:99999999', 'decimal:0,4'],
        ]);
        $merged = $data + ($current?->only(['from_product_id', 'to_product_id', 'factor']) ?? []);

        if ((int) $merged['from_product_id'] === (int) $merged['to_product_id']) {
            throw ValidationException::withMessages(['to_product_id' => 'Origem e destino da conversão precisam ser produtos diferentes.']);
        }

        $products = $this->scoped(Product::query(), $request->user())->whereIn('id', [$merged['from_product_id'], $merged['to_product_id']])->get()->keyBy('id');
        $from = $products->get((int) $merged['from_product_id']);
        $to = $products->get((int) $merged['to_product_id']);

        if ($from === null || $to === null) {
            throw ValidationException::withMessages([$from === null ? 'from_product_id' : 'to_product_id' => 'Produto não encontrado nesta organização.']);
        }

        if (! $from->controls_stock || ! $to->controls_stock) {
            throw ValidationException::withMessages(['from_product_id' => 'Os dois produtos precisam controlar estoque.']);
        }

        if ((int) $to->decimal_places_allowed < 1) {
            throw ValidationException::withMessages(['to_product_id' => sprintf('"%s" é vendido em unidades inteiras: o destino do granel precisa ter unidade fracionável (kg, l, m…).', $to->name)]);
        }

        $duplicate = $this->scoped(ProductConversion::query(), $request->user())->where('from_product_id', $from->id)->where('to_product_id', $to->id)
            ->when($current !== null, fn ($q) => $q->whereKeyNot($current->id))->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['to_product_id' => 'Já existe conversão entre estes dois produtos.']);
        }

        return ['from_product_id' => $from->id, 'to_product_id' => $to->id, 'factor' => (string) Decimal::of($merged['factor'])->toScale(4)];
    }

    private function authorizeManage(User $user): void
    {
        abort_unless($this->permissions->allowsInOrganization($user, $this->scope->primaryOrganizationId($user), self::MANAGE), 403, 'Você não tem permissão para alterar o estoque desta organização.');
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function scoped(Builder $query, User $user): Builder
    {
        return $this->scope->scopeQuery($query, $user);
    }

    private function organizationFor(User $user): Organization
    {
        $id = $this->scope->primaryOrganizationId($user);
        abort_if($id === null, 404, 'Profissional sem organização usa a etiqueta padrão da balança.');

        return Organization::findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function presentScaleSettings(Organization $organization): array
    {
        return ($organization->scale_settings ?? []) + ScaleBarcodeParser::DEFAULTS;
    }

    /** @return array<string, mixed> */
    private function presentConversion(ProductConversion $c): array
    {
        return [
            'id' => $c->id,
            'from_product' => ['id' => $c->from_product_id, 'name' => $c->fromProduct?->name, 'unit_of_sale' => $c->fromProduct?->unit_of_sale],
            'to_product' => ['id' => $c->to_product_id, 'name' => $c->toProduct?->name, 'unit_of_sale' => $c->toProduct?->unit_of_sale],
            'factor' => (string) $c->factor,
        ];
    }

    /** @return array<string, mixed> */
    private function presentOpening(PackageOpening $o): array
    {
        return [
            'id' => $o->id,
            'from_product' => ['id' => $o->from_product_id, 'name' => $o->fromProduct?->name],
            'to_product' => ['id' => $o->to_product_id, 'name' => $o->toProduct?->name],
            'quantity' => (string) $o->quantity,
            'factor' => (string) $o->factor,
            'entered_quantity' => (string) $o->entered_quantity,
            'total_cost' => (string) $o->total_cost,
            'notes' => $o->notes,
            'created_at' => $o->created_at?->toIso8601String(),
        ];
    }
}
