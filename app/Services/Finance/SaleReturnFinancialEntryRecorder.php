<?php

namespace App\Services\Finance;

use App\Enums\FinancialEntryStatus;
use App\Enums\FinancialNature;
use App\Models\FinancialEntry;
use App\Models\SaleReceipt;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Money\Money;

/**
 * Devolução com reembolso em dinheiro → saída no financeiro (`financial_entries`), o espelho do
 * `SaleFinancialEntryRecorder` (a venda paga lança a receita; a devolução lança o que voltou).
 *
 * Regra:
 *  - natureza DESPESA (é dinheiro que sai), já baixada (`paid`): competência E caixa na data da
 *    devolução — a venda original não é reescrita;
 *  - um lançamento para os itens ("Devoluções de vendas") e outro para o acréscimo de repasse da
 *    taxa devolvido na política `proportional` ("Estorno de acréscimo de parcelamento"), como a
 *    venda separa a receita do acréscimo. Σ lançamentos = `sale_returns.total`, ao centavo;
 *  - conta e forma do recebimento de onde o dinheiro volta (o mesmo do movimento de caixa);
 *  - referência `sale_return`/id, idempotente: rodar de novo não lança outra vez.
 * Só `cash` chega aqui. Crédito na conta corrente do cliente não é saída de dinheiro.
 */
final class SaleReturnFinancialEntryRecorder
{
    public const REFERENCE_TYPE = 'sale_return';

    private const ITEMS_CATEGORY = 'Devoluções de vendas';

    private const SURCHARGE_CATEGORY = 'Estorno de acréscimo de parcelamento';

    public function __construct(private readonly FinancialCategoryProvisioner $categories) {}

    public function recordCashRefund(SaleReturn $return, ?SaleReceipt $receipt, Money $itemsRefund, Money $surchargeRefund, User $user): void
    {
        $exists = FinancialEntry::query()
            ->where('reference_type', self::REFERENCE_TYPE)
            ->where('reference_id', $return->id)
            ->exists();

        if ($exists) {
            return;
        }

        foreach ([self::ITEMS_CATEGORY => $itemsRefund, self::SURCHARGE_CATEGORY => $surchargeRefund] as $category => $amount) {
            if ($amount->isPositive()) {
                $this->store($return, $receipt, $user, $category, $amount);
            }
        }
    }

    private function store(SaleReturn $return, ?SaleReceipt $receipt, User $user, string $categoryName, Money $amount): void
    {
        $ownership = ['organization_id' => $return->organization_id, 'professional_id' => $return->professional_id];
        $category = $this->categories->systemCategoryFor($ownership, FinancialNature::EXPENSE, $categoryName);
        $date = ($return->created_at ?? now())->toDateString();

        FinancialEntry::create($ownership + [
            'financial_category_id' => $category->id,
            'account_id' => $receipt?->account_id,
            'payment_method_id' => $receipt?->payment_method_id,
            'description' => sprintf('Devolução %d da venda %s — %s', $return->id, $return->sale?->number ?? $return->sale_id, $categoryName),
            'nature' => FinancialNature::EXPENSE,
            'due_date' => $date,
            'accrual_date' => $date,
            'amount' => $amount->toString(),
            'net_amount' => $amount->toString(),
            'paid_at' => $return->created_at ?? now(),
            'paid_amount' => $amount->toString(),
            'status' => FinancialEntryStatus::PAID,
            'reference_type' => self::REFERENCE_TYPE,
            'reference_id' => $return->id,
            'created_by' => $user->id,
        ]);
    }
}
