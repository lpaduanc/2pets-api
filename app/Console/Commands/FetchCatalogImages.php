<?php

namespace App\Console\Commands;

use App\Enums\CatalogImageStatus;
use App\Jobs\FetchCatalogProductImage;
use App\Models\CatalogProduct;
use App\Services\Catalog\CosmosClient;
use Illuminate\Console\Command;

/**
 * `catalog:fetch-images` — enfileira a busca de foto por EAN para os itens do catálogo global
 * ainda sem imagem. `--limit` existe porque o plano do Cosmos tem cota diária: a carga inicial
 * roda em lotes (ex.: agendado 1x por dia) em vez de estourar a cota numa tacada.
 */
class FetchCatalogImages extends Command
{
    protected $signature = 'catalog:fetch-images {--limit=200 : Máximo de itens enfileirados nesta execução}';

    protected $description = 'Busca no Cosmos (por EAN) a foto dos itens do catálogo global sem imagem.';

    public function handle(CosmosClient $cosmos): int
    {
        if (! $cosmos->isConfigured()) {
            $this->error('COSMOS_TOKEN não configurado.');

            return self::FAILURE;
        }

        $ids = CatalogProduct::query()
            ->whereNotNull('ean')
            ->whereIn('image_status', CatalogImageStatus::retryable())
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        $ids->each(fn (int $id) => FetchCatalogProductImage::dispatch($id));
        $this->info("{$ids->count()} item(ns) enfileirado(s).");

        return self::SUCCESS;
    }
}
