<?php

namespace App\Jobs;

use App\Enums\CatalogImageStatus;
use App\Models\CatalogProduct;
use App\Services\Catalog\CosmosClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Busca a foto (e o NCM, de quebra) de um item do catálogo global no Cosmos pelo EAN e guarda
 * a imagem no disco `public` — a foto passa a ser nossa e não depende do link externo.
 *
 * Idempotente: item que já tem foto (`found`/`uploaded`) é ignorado, então reenfileirar o lote
 * inteiro não sobrescreve upload manual do admin.
 */
final class FetchCatalogProductImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> segundos — 429 do plano gratuito costuma liberar em minutos */
    public array $backoff = [120, 900];

    public function __construct(private readonly int $catalogProductId) {}

    public function handle(CosmosClient $cosmos): void
    {
        $product = CatalogProduct::find($this->catalogProductId);

        if (! $product || ! $product->ean || ! in_array($product->image_status->value, CatalogImageStatus::retryable(), true)) {
            return;
        }

        $data = $cosmos->findByEan($product->ean);
        $image = $data && filled($data['thumbnail'] ?? null) ? $cosmos->downloadImage($data['thumbnail']) : null;

        $product->fill([
            'ncm' => $product->ncm ?? ($data['ncm']['code'] ?? null),
            'image_checked_at' => now(),
            'image_status' => $image ? CatalogImageStatus::FOUND : CatalogImageStatus::NOT_FOUND,
            'image_path' => $image ? $this->store($product, $image) : $product->image_path,
        ])->save();
    }

    public function failed(): void
    {
        CatalogProduct::whereKey($this->catalogProductId)->update([
            'image_status' => CatalogImageStatus::FAILED->value,
            'image_checked_at' => now(),
        ]);
    }

    private function store(CatalogProduct $product, string $binary): string
    {
        $path = "catalog/{$product->ean}.jpg";
        Storage::disk('public')->put($path, $binary);

        return '/storage/'.$path;
    }
}
