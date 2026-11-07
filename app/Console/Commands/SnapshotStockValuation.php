<?php

namespace App\Console\Commands;

use App\Services\Insights\Widgets\StockValuationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Foto diária do estoque valorizado de todo escopo com produto (PLANO 3.6). Idempotente por dia:
 * rodar de novo regrava a foto do mesmo dia. Sem `--date`, o dia de hoje no fuso de São Paulo
 * (o agendamento das 23:55 roda nesse fuso; em UTC já seria o dia seguinte).
 */
class SnapshotStockValuation extends Command
{
    protected $signature = 'stock:snapshot-valuation {--date= : Dia da foto (YYYY-MM-DD); padrão: hoje em America/Sao_Paulo}';

    protected $description = 'Grava a foto diária do estoque valorizado (custo e venda) por organização.';

    public function handle(StockValuationService $valuation): int
    {
        $date = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::now('America/Sao_Paulo')->startOfDay();
        $scopes = $valuation->scopesWithStock();

        foreach ($scopes as $scope) {
            $valuation->snapshot($scope['organization_id'], $scope['professional_id'], $date);
        }

        $this->info(sprintf('Fotos de estoque gravadas para %s: %d escopo(s).', $date->toDateString(), count($scopes)));

        return self::SUCCESS;
    }
}
