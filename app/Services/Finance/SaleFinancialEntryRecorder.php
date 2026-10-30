<?php

namespace App\Services\Finance;

use App\Enums\FinancialEntryStatus;
use App\Enums\FinancialNature;
use App\Models\FinancialEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money\Money;

/**
 * Venda de balcão paga → `financial_entries` de receita — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * Um lançamento para "Produtos" e outro para "Serviços" quando a venda mistura os dois,
 * reaproveitando `FinancialOverviewService::saleTotalsByItemType()` — o MESMO rateio de
 * desconto que a tela de Financeiro do PDV já usa, para não haver duas contas divergentes da
 * mesma venda.
 *
 * O acréscimo de repasse da taxa de cartão (plano 1.1) é receita própria, em "Acréscimo de
 * parcelamento": misturá-lo em Produtos/Serviços inflaria a receita de venda de item, e no DRE a
 * clínica precisa ver quanto o repasse cobre da despesa de adquirente.
 */
final class SaleFinancialEntryRecorder
{
    /** @var array<string, string> */
    private const CATEGORY_BY_TYPE = ['product' => 'Produtos', 'service' => 'Serviços'];

    private const SURCHARGE_CATEGORY = 'Acréscimo de parcelamento';

    public function __construct(
        private readonly FinancialCategoryProvisioner $categories,
        private readonly FinancialOverviewService $overview,
    ) {}

    public function recordForFullyPaidSale(Sale $sale, User $user): void
    {
        if (FinancialEntry::where('reference_type', 'sale')->where('reference_id', $sale->id)->exists()) {
            return;
        }

        $byType = $this->overview->saleTotalsByItemType($sale);
        $accrualDate = ($sale->sold_at ?? now())->toDateString();
        $paidAt = $sale->receipts->max('received_at') ?? now();

        foreach (self::CATEGORY_BY_TYPE as $type => $label) {
            if ($byType[$type] <= 0) {
                continue;
            }

            $this->storeRevenueEntry($sale, $user, $label, (float) $byType[$type], $accrualDate, $paidAt);
        }

        $surcharge = Money::of($sale->surcharge_amount);

        if ($surcharge->isPositive()) {
            $this->storeRevenueEntry($sale, $user, self::SURCHARGE_CATEGORY, $surcharge->toFloat(), $accrualDate, $paidAt);
        }
    }

    private function storeRevenueEntry(Sale $sale, User $user, string $categoryName, float $amount, string $accrualDate, mixed $paidAt): void
    {
        $category = $this->categories->systemCategory($user, FinancialNature::REVENUE, $categoryName, 'Receita de vendas');

        FinancialEntry::create([
            'organization_id' => $sale->organization_id,
            'professional_id' => $sale->professional_id,
            'financial_category_id' => $category->id,
            'description' => sprintf('Venda %s — %s', $sale->number ?? $sale->id, $categoryName),
            'nature' => FinancialNature::REVENUE,
            'due_date' => $accrualDate,
            'accrual_date' => $accrualDate,
            'amount' => $amount,
            'net_amount' => $amount,
            'paid_at' => $paidAt,
            'paid_amount' => $amount,
            'status' => FinancialEntryStatus::PAID,
            'reference_type' => 'sale',
            'reference_id' => $sale->id,
            'created_by' => $user->id,
        ]);
    }
}
