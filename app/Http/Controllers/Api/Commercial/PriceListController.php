<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Services\Commercial\PriceListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lista de preços do balcão — contrato docs/gap-simplesvet/08, "Lista de preços".
 *
 * Não é paginada de propósito: é uma tabela de CONSULTA E IMPRESSÃO, e uma lista de preços
 * que sai impressa pela metade não serve para nada. O filtro `show_in_price_list` já é o que
 * mantém o volume sob controle (`PriceListService`).
 */
class PriceListController extends Controller
{
    public function __construct(private readonly PriceListService $priceList) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->priceList->forCounter(
            $request->user(),
            $request->filled('search') ? $request->string('search')->toString() : null,
            $request->filled('product_group_id') ? $request->integer('product_group_id') : null,
        );

        return response()->json([
            'data' => $items,
            'meta' => ['total' => $items->count()],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $csv = $this->priceList->toCsv(
            $request->user(),
            $request->filled('search') ? $request->string('search')->toString() : null,
            $request->filled('product_group_id') ? $request->integer('product_group_id') : null,
        );

        $filename = 'lista-de-precos-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(
            function () use ($csv): void {
                // BOM UTF-8: sem ele o Excel no Windows abre "Ração" como "RaÃ§Ã£o".
                echo "\xEF\xBB\xBF".$csv;
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
