<?php

namespace App\Console\Commands;

use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Services\Commercial\Profitability\SaleProfitabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Calcula a rentabilidade das vendas pagas já existentes (docs/gap-petflow/PLANO.md, 1.5): taxa
 * = `operator_fee` gravado em cada recebimento, custo = `unit_cost` congelado. Idempotente — rodar
 * de novo só regrava o que mudou.
 */
class BackfillSaleProfitability extends Command
{
    protected $signature = 'profitability:backfill {--from= : Data inicial (YYYY-MM-DD) de sold_at; sem ela, todas}';

    protected $description = 'Calcula sale_item_profitability das vendas pagas (PLANO 1.5).';

    public function handle(SaleProfitabilityService $profitability): int
    {
        $count = 0;

        Sale::query()
            ->where('kind', SaleKind::SALE->value)
            ->where('status', SaleStatus::PAID->value)
            ->when($this->option('from'), fn ($query, string $from) => $query->where('sold_at', '>=', CarbonImmutable::parse($from)->startOfDay()))
            ->chunkById(200, function ($sales) use ($profitability, &$count): void {
                foreach ($sales as $sale) {
                    $profitability->recompute($sale);
                    $count++;
                }
            });

        $this->info("Rentabilidade calculada para {$count} venda(s).");

        return self::SUCCESS;
    }
}
