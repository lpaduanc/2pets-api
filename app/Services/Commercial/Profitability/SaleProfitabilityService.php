<?php

namespace App\Services\Commercial\Profitability;

use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleItemProfitability;
use Illuminate\Support\Facades\DB;

/**
 * Rentabilidade por item da venda (docs/gap-petflow/PLANO.md, 1.5). `recompute` grava
 * `sale_item_profitability` a partir dos snapshots da venda — só venda FINALIZADA (`paid`) tem
 * resultado; qualquer outra tem as linhas apagadas. Idempotente: a linha só ganha versão nova
 * quando algum valor mudou; rodar de novo sem mudança não escreve nada.
 *
 * Disparado pelos eventos que mudam o resultado (`RecomputeSaleProfitability`); `report` também
 * calcula na hora a venda paga que ainda não tem linhas (fila sem worker, venda antiga sem
 * backfill), para a tela nunca mostrar "pendente" numa venda fechada.
 */
final class SaleProfitabilityService
{
    public function __construct(
        private readonly SaleProfitabilityInputs $inputs,
        private readonly ProfitabilityCalculator $calculator,
        private readonly SaleProfitabilityReport $reports,
    ) {}

    public function hasResult(Sale $sale): bool
    {
        return $sale->kind === SaleKind::SALE && $sale->status === SaleStatus::PAID;
    }

    public function recompute(Sale $sale): void
    {
        DB::transaction(function () use ($sale): void {
            // Trava a venda: dois eventos da mesma venda em paralelo não gravam versões cruzadas.
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if (! $this->hasResult($sale)) {
                SaleItemProfitability::where('sale_id', $sale->id)->delete();

                return;
            }

            $result = $this->calculator->calculate($this->inputs->for($sale));
            $this->store($sale, $result['items']);
        });
    }

    /** @return array{status: string, computed_at: string|null, version: int|null, items: list<array<string, mixed>>, totals: array<string, string|null>|null} */
    public function report(Sale $sale): array
    {
        if (! $this->hasResult($sale)) {
            return ['status' => 'pending', 'computed_at' => null, 'version' => null, 'items' => [], 'totals' => null];
        }

        if (! SaleItemProfitability::where('sale_id', $sale->id)->exists()) {
            $this->recompute($sale);
        }

        return $this->reports->from($sale->fresh('items'), SaleItemProfitability::where('sale_id', $sale->id)->get());
    }

    /** @param  list<array<string, mixed>>  $items */
    private function store(Sale $sale, array $items): void
    {
        $existing = SaleItemProfitability::where('sale_id', $sale->id)->get()->keyBy('sale_item_id');
        SaleItemProfitability::where('sale_id', $sale->id)->whereNotIn('sale_item_id', array_column($items, 'sale_item_id'))->delete();

        foreach ($items as $item) {
            $values = ['quantity' => $item['quantity']] + array_intersect_key($item, array_flip(SaleItemProfitability::MONEY_COLUMNS));
            $row = $existing->get($item['sale_item_id']);

            if ($row !== null && $this->sameValues($row, $values)) {
                continue;
            }

            SaleItemProfitability::updateOrCreate(['sale_item_id' => $item['sale_item_id']], $values + [
                'organization_id' => $sale->organization_id,
                'professional_id' => $sale->professional_id,
                'sale_id' => $sale->id,
                'computed_at' => now(),
                'version' => ($row?->version ?? 0) + 1,
            ]);
        }
    }

    /** @param  array<string, string>  $values */
    private function sameValues(SaleItemProfitability $row, array $values): bool
    {
        foreach ($values as $column => $value) {
            if ((string) $row->{$column} !== $value) {
                return false;
            }
        }

        return true;
    }
}
