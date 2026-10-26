<?php

namespace App\Services\Catalog;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente mínimo da API Cosmos/Bluesoft (`GET /gtins/{ean}.json`). Fonte licenciada de foto e
 * NCM por código de barras para o catálogo global — confira no contrato do plano contratado se
 * a imagem pode ser armazenada e exibida aos clientes do 2pets.
 */
class CosmosClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.cosmos.token'));
    }

    /**
     * @return array<string, mixed>|null null quando o EAN não existe na base do Cosmos
     *
     * @throws RequestException|ConnectionException em falha técnica (429, 5xx, rede) — o job retenta
     */
    public function findByEan(string $ean): ?array
    {
        $response = Http::baseUrl(config('services.cosmos.base_url'))
            ->withHeaders([
                'X-Cosmos-Token' => config('services.cosmos.token'),
                // A API recusa requisição sem este User-Agent (documentação do Cosmos).
                'User-Agent' => 'Cosmos-API-Request',
            ])
            ->acceptJson()
            ->timeout(20)
            ->get("/gtins/{$ean}.json");

        if ($response->notFound()) {
            return null;
        }

        return $response->throw()->json();
    }

    /** Baixa o binário da imagem; null se o link não devolver uma imagem. */
    public function downloadImage(string $url): ?string
    {
        $response = Http::timeout(30)->get($url);

        if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            return null;
        }

        return $response->body();
    }
}
