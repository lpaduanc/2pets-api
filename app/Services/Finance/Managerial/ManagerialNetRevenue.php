<?php

namespace App\Services\Finance\Managerial;

use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * "Receita líquida" — a definição ÚNICA que as metas de venda (PLANO 2.5) usam como realizado,
 * tirada das mesmas fontes da DRE gerencial (1.7), sem conta paralela: a chave `sales_revenue`
 * de `ManagerialSalesTotals` (vendas pagas, não canceladas, `kind = sale`, mês da venda; total
 * da linha menos o desconto do cabeçalho rateado e menos o devolvido; antes de imposto, taxa e
 * comissão) somada à de `ManagerialPackageRevenue` (sessão de pacote no mês da baixa e saldo
 * vencido como receita de expiração, 2.7; a linha do pacote não entra na venda).
 */
final class ManagerialNetRevenue
{
    public function __construct(
        private readonly ManagerialSalesTotals $sales,
        private readonly ManagerialPackageRevenue $packages,
    ) {}

    /** @return array<string, Money> receita líquida do mês por chave da dimensão (`unallocated` = sem chave) */
    public function forMonth(User $user, string $month, ManagerialSalesDimension $dimension): array
    {
        $from = CarbonImmutable::parse($month.'-01');
        $to = $from->endOfMonth();
        $merged = ManagerialCostCenters::mergeCenters(
            $this->sales->byDimensionAndMonth($user, $from, $to, $dimension),
            $this->packages->byDimensionAndMonth($user, $from, $to, $dimension),
        );

        return array_map(fn (array $months): Money => Money::of($months[$month]['sales_revenue'] ?? '0'), $merged);
    }

    public function totalForMonth(User $user, string $month): Money
    {
        return Money::sum($this->forMonth($user, $month, ManagerialSalesDimension::ITEM_TYPE));
    }
}
