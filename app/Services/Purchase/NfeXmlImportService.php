<?php

namespace App\Services\Purchase;

use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Importação de NF-e do fornecedor — docs/gap-simplesvet/06.
 *
 * `preview()` NÃO grava compra: devolve o rascunho casado para a tela de conferência, e o XML
 * fica guardado sob um token (por usuário) que o `POST purchases` anexa depois. Casamento de
 * item, nesta ordem:
 *
 *  1. GTIN (código de barras) — firme;
 *  2. código do fornecedor já aprendido em compra anterior (`supplier_products`) — firme;
 *  3. similaridade de nome (`pg_trgm`) — só SUGESTÃO, o usuário confirma.
 *
 * O que não casa volta com `match = null` e exige decisão explícita antes de gravar
 * (critério de aceite: "item novo exige decisão explícita do usuário").
 */
final class NfeXmlImportService
{
    public const DISK = 'local';

    private const SIMILARITY_THRESHOLD = 0.3;

    public function __construct(
        private readonly NfeXmlParser $parser,
        private readonly CommercialScopeResolver $scope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(UploadedFile $file, User $user): array
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $parsed = $this->parser->parse($contents);

        $token = (string) Str::uuid();
        Storage::disk(self::DISK)->put($this->tokenPath($user, $token), $contents);

        $supplier = $this->matchSupplier($parsed['supplier']['document'], $user);

        return [
            'xml_token' => $token,
            'supplier' => [
                'id' => $supplier?->id,
                'match' => $supplier === null ? null : 'document',
                'data' => $parsed['supplier'],
            ],
            'invoice' => $parsed['invoice'],
            'duplicate_purchase_id' => $this->duplicateOf($parsed['invoice']['key'], $user),
            'items' => array_map(fn (array $item): array => $item + $this->matchItem($item, $supplier, $user), $parsed['items']),
        ];
    }

    /** Caminho do XML guardado, ou `null` se o token não é deste usuário/não existe. */
    public function resolveToken(User $user, string $token): ?string
    {
        if (! Str::isUuid($token)) {
            return null;
        }

        $path = $this->tokenPath($user, $token);

        return Storage::disk(self::DISK)->exists($path) ? $path : null;
    }

    public function duplicateOf(?string $invoiceKey, User $user, ?int $ignoreId = null): ?int
    {
        if ($invoiceKey === null) {
            return null;
        }

        return $this->scope->scopeQuery(Purchase::query(), $user)
            ->where('invoice_key', $invoiceKey)
            ->where('status', '!=', PurchaseStatus::CANCELLED->value)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->value('id');
    }

    private function matchSupplier(?string $document, User $user): ?Supplier
    {
        if ($document === null) {
            return null;
        }

        return $this->scope->scopeQuery(Supplier::query(), $user)
            ->where('document', preg_replace('/\D/', '', $document))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{match: array<string, mixed>|null, suggestions: list<array<string, mixed>>}
     */
    private function matchItem(array $item, ?Supplier $supplier, User $user): array
    {
        $products = fn () => $this->scope->scopeQuery(Product::query(), $user)->where('is_active', true);

        if ($item['gtin'] !== null) {
            $product = $products()->where('gtin', $item['gtin'])->first();

            if ($product !== null) {
                return ['match' => $this->matchPayload($product, 'gtin', 1.0), 'suggestions' => []];
            }
        }

        if ($supplier !== null && $item['supplier_product_code'] !== null) {
            $productId = SupplierProduct::query()
                ->where('supplier_id', $supplier->id)
                ->where('supplier_product_code', $item['supplier_product_code'])
                ->value('product_id');

            $product = $productId === null ? null : $products()->find($productId);

            if ($product !== null) {
                return ['match' => $this->matchPayload($product, 'supplier_code', 1.0), 'suggestions' => []];
            }
        }

        $suggestions = $this->similarProducts((string) $item['description_on_invoice'], $user);
        $best = $suggestions[0] ?? null;

        return [
            'match' => $best === null ? null : [
                'product_id' => $best['product_id'],
                'product_name' => $best['name'],
                'method' => 'similarity',
                'score' => $best['score'],
            ],
            'suggestions' => $suggestions,
        ];
    }

    /**
     * @return list<array{product_id: int, name: string, score: float}>
     */
    private function similarProducts(string $description, User $user): array
    {
        if ($description === '' || DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        return $this->scope->scopeQuery(Product::query(), $user)
            ->where('is_active', true)
            ->select('products.id', 'products.name')
            ->selectRaw('similarity(immutable_unaccent(lower(products.name)), immutable_unaccent(lower(?))) AS score', [$description])
            ->whereRaw('similarity(immutable_unaccent(lower(products.name)), immutable_unaccent(lower(?))) >= ?', [$description, self::SIMILARITY_THRESHOLD])
            ->orderByDesc('score')
            ->limit(3)
            ->get()
            ->map(fn (Product $product): array => [
                'product_id' => $product->id,
                'name' => $product->name,
                'score' => round((float) $product->getAttribute('score'), 2),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function matchPayload(Product $product, string $method, float $score): array
    {
        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'method' => $method,
            'score' => $score,
        ];
    }

    private function tokenPath(User $user, string $token): string
    {
        return "purchase-xml/{$user->id}/{$token}.xml";
    }
}
