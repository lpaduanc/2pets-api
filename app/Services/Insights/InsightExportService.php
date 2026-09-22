<?php

namespace App\Services\Insights;

use App\DataTransferObjects\InsightQuery;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\Csv\CsvFormulaGuard;

/**
 * CSV do drill-down (ou da série inteira, quando `$bucket` é `null`) — separador `;` e vírgula
 * decimal, mesma prática já usada em `App\Services\Commercial\PriceListService::toCsv()`
 * (doc 07). `lazy()` em vez de `get()`: exportação não é paginada, o período pode ter
 * milhares de itens, e não há por que carregar todos na memória de uma vez — `cursor()` faria
 * o mesmo, mas não hidrata relação eager-loaded (`with()`), o que viraria N+1 aqui.
 */
final class InsightExportService
{
    private const HEADER = ['Data', 'Venda', 'Status', 'Cliente', 'Animal', 'Produto', 'Qtd', 'Bruto', 'Desconto', 'Líquido'];

    public function __construct(private readonly InsightsDrillDownService $drillDown) {}

    public function toCsv(User $user, InsightQuery $query, ?string $bucket): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::HEADER, ';');

        foreach ($this->rows($user, $query, $bucket) as $row) {
            fputcsv($handle, $row, ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @return iterable<int, list<string>>
     */
    private function rows(User $user, InsightQuery $query, ?string $bucket): iterable
    {
        $items = $this->drillDown->filteredItems($user, $query, $bucket)
            ->select('sale_items.*')
            ->with(['sale.client:id,name', 'sale.pet:id,name']);

        foreach ($items->lazy() as $item) {
            yield $this->row($item);
        }
    }

    /**
     * @return list<string>
     */
    private function row(SaleItem $item): array
    {
        $sale = $item->sale;

        // CSV/Formula Injection (revisão de segurança, achado Médio 3): só as colunas de
        // texto livre (nome de cliente/pet, descrição de item) passam pelo guard — as
        // colunas numéricas abaixo são formatadas por `number_format()` e um total negativo
        // legítimo (estorno) não pode virar texto.
        return [
            ($sale->sold_at ?? $sale->created_at)->toDateString(),
            (string) ($sale->number ?? $sale->id),
            $sale->status->label(),
            CsvFormulaGuard::sanitize($sale->client?->name ?? ''),
            CsvFormulaGuard::sanitize($sale->pet?->name ?? ''),
            CsvFormulaGuard::sanitize($item->description),
            number_format((float) $item->quantity, 3, ',', ''),
            number_format((float) $item->quantity * (float) $item->unit_price, 2, ',', ''),
            number_format((float) $item->discount, 2, ',', ''),
            number_format((float) $item->total, 2, ',', ''),
        ];
    }
}
